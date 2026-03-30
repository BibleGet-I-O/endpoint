<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Services\EmbeddingClient;
use BibleGet\Api\Services\EmbeddingModelValidator;
use BibleGet\Api\Util\StringUtils;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class SemanticSearchHandler extends AbstractHandler
{
    private const ENDPOINT_VERSION  = '3.0';
    private const DEFAULT_LIMIT     = 20;
    private const MAX_LIMIT         = 100;
    private const DEFAULT_THRESHOLD = 0.0;

    /** @var list<string>|null */
    private static ?array $cachedValidVersions = null;

    private LoggerInterface $logger;
    private EmbeddingClient $embeddingClient;

    /**
     * @param string[] $requestPathParams
     */
    public function __construct(ResponseFactoryInterface $responseFactory, array $requestPathParams = [], ?EmbeddingClient $embeddingClient = null)
    {
        parent::__construct($responseFactory, $requestPathParams);
        $this->embeddingClient = $embeddingClient ?? new EmbeddingClient();
    }

    /**
     * Reset cached data (for testing only).
     */
    public static function resetCache(): void
    {
        self::$cachedValidVersions = null;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Nyholm\Psr7\Response(200, [], null, $request->getProtocolVersion(), 'OK');
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);
        $this->validateRequestContentType($request);
        $this->logger = LoggerFactory::create('api');

        $params      = $this->getRequestParams($request);
        $contentType = $this->resolveResponseContentType($request, $params);
        $response    = $this->initResponse($request, $contentType);

        [$query, $version, $limit, $threshold] = $this->extractParams($params);

        $pdo     = Connection::getConnection();
        $version = $this->validateVersion($pdo, $version);
        $this->assertValidVersionFormat($version);

        // Embed the user's query via the Python microservice
        $embedStart = microtime(true);
        try {
            $queryVector = $this->embeddingClient->embed($query);
        } catch (ServiceUnavailableException $e) {
            $this->logger->warning('Embedding service unavailable: ' . $e->getMessage());
            throw new ServiceUnavailableException(
                'Semantic search is temporarily unavailable. Use /v3/search/keyword for text-based search.'
            );
        }
        $embedMs = round(( microtime(true) - $embedStart ) * 1000, 1);
        $this->logger->info('Embedding latency: ' . $embedMs . 'ms for query: ' . substr($query, 0, 100));

        // Warn if stored embeddings were computed with a different model
        $serviceModel = $this->embeddingClient->getLastModel();
        if ($serviceModel !== '') {
            EmbeddingModelValidator::validate($pdo, $version, $serviceModel, $this->logger);
        }

        // Run pgvector cosine similarity search
        $dbStart = microtime(true);
        $results = $this->executeSimilaritySearch($pdo, $version, $queryVector, $limit, $threshold);
        $dbMs    = round(( microtime(true) - $dbStart ) * 1000, 1);
        $this->logger->info('Similarity search latency: ' . $dbMs . 'ms (' . count($results) . ' results)');

        $body          = new \stdClass();
        $body->results = $results;
        $body->errors  = [];
        $body->info    = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        $response = $this->buildResponse($response, $contentType, $body, $results);
        return $this->withCacheHeaders($request, $response);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, string, int, float}
     */
    private function extractParams(array $params): array
    {
        $queryRaw = $params['query'] ?? '';
        $query    = is_string($queryRaw) ? trim($queryRaw) : '';

        $versionRaw = $params['version'] ?? '';
        $version    = is_string($versionRaw) ? $versionRaw : '';

        $limitRaw = $params['limit'] ?? self::DEFAULT_LIMIT;
        $limit    = is_numeric($limitRaw) ? (int) $limitRaw : self::DEFAULT_LIMIT;
        $limit    = max(1, min($limit, self::MAX_LIMIT));

        $thresholdRaw = $params['threshold'] ?? self::DEFAULT_THRESHOLD;
        $threshold    = is_numeric($thresholdRaw) ? (float) $thresholdRaw : self::DEFAULT_THRESHOLD;
        $threshold    = max(0.0, min($threshold, 1.0));

        if ($query === '') {
            throw new ValidationException('The query parameter is required.');
        }
        if ($version === '') {
            throw new ValidationException('The version parameter is required.');
        }

        return [$query, $version, $limit, $threshold];
    }

    private function validateVersion(\PDO $pdo, string $version): string
    {
        if (self::$cachedValidVersions === null) {
            self::$cachedValidVersions = [];
            $result                    = $pdo->query('SELECT sigla FROM versions_available');
            if ($result === false) {
                $this->logger->error('Failed to query versions_available');
                throw new InternalServerErrorException('An internal database error occurred.');
            }
            while (is_array($row = $result->fetch(\PDO::FETCH_ASSOC))) {
                self::$cachedValidVersions[] = StringUtils::asString($row['sigla']);
            }
        }
        $version = strtoupper($version);
        if (!in_array($version, self::$cachedValidVersions)) {
            throw new ValidationException('Not a valid version: ' . $version);
        }
        return $version;
    }

    private function assertValidVersionFormat(string $version): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $version)) {
            throw new ValidationException('Invalid version identifier format: ' . $version);
        }
    }

    /**
     * @param float[] $queryVector
     * @return array<int, array<string, mixed>>
     */
    private function executeSimilaritySearch(\PDO $pdo, string $version, array $queryVector, int $limit, float $threshold): array
    {
        $vectorStr = '[' . implode(',', $queryVector) . ']';

        // Load version index for book name mapping
        $versionIndex = $this->loadVersionIndex($pdo, $version);

        $sql = 'SELECT *, 1 - (embedding <=> ?::vector) AS similarity '
             . 'FROM "' . $version . '" '
             . 'WHERE embedding IS NOT NULL '
             . 'AND 1 - (embedding <=> ?::vector) >= ? '
             . 'ORDER BY embedding <=> ?::vector '
             . 'LIMIT ?';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$vectorStr, $vectorStr, $threshold, $vectorStr, $limit]);

        $results = [];
        while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $bookidx = array_search($row['book'], $versionIndex['book_num']);

            $entry     = [
                'version'     => $version,
                'testament'   => is_numeric($row['testament']) ? (int) $row['testament'] : 0,
                'text'        => StringUtils::asString($row['text'] ?? ''),
                'bookabbrev'  => $bookidx !== false ? ( $versionIndex['abbreviations'][$bookidx] ?? '' ) : '',
                'booknum'     => $bookidx !== false ? (int) $bookidx : 0,
                'univbooknum' => $row['book'],
                'book'        => $bookidx !== false ? ( $versionIndex['books'][$bookidx] ?? '' ) : '',
                'section'     => is_numeric($row['section']) ? (int) $row['section'] : 0,
                'chapter'     => is_numeric($row['chapter']) ? (int) $row['chapter'] : 0,
                'verse'       => is_numeric($row['verse']) ? (int) $row['verse'] : 0,
                'similarity'  => isset($row['similarity']) && is_numeric($row['similarity']) && is_finite((float) $row['similarity'])
                    ? round((float) $row['similarity'], 4)
                    : 0.0,
            ];
            $results[] = $entry;
        }

        return $results;
    }

    /**
     * @return array{abbreviations: list<string>, books: list<string>, book_num: list<string>}
     */
    private function loadVersionIndex(\PDO $pdo, string $version): array
    {
        $abbreviations = $books = $book_num = [];
        try {
            $idxResult = $pdo->query('SELECT * FROM "' . $version . '_idx"');
        } catch (\PDOException $e) {
            $this->logger->error('Failed to load index for version ' . $version . ': ' . $e->getMessage());
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        if ($idxResult === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        while (is_array($row = $idxResult->fetch(\PDO::FETCH_ASSOC))) {
            $abbreviations[] = StringUtils::asString($row['abbrev'] ?? '');
            $books[]         = StringUtils::asString($row['fullname'] ?? '');
            $book_num[]      = StringUtils::asString($row['book'] ?? '');
        }
        if (empty($abbreviations)) {
            throw new InternalServerErrorException('No index data found for version: ' . $version);
        }
        return ['abbreviations' => $abbreviations, 'books' => $books, 'book_num' => $book_num];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function buildResponse(ResponseInterface $response, string $contentType, \stdClass $body, array $results): ResponseInterface
    {
        if ($contentType === 'application/json') {
            return $this->jsonResponse($response, $body);
        }

        if ($contentType === 'application/xml') {
            $root = '<?xml version="1.0" encoding="UTF-8"?><BibleGetSemanticSearch/>';
            $xml  = new \SimpleXMLElement($root);
            $xml->addChild('errors');
            $xmlInfo    = $xml->addChild('info');
            $xmlResults = $xml->addChild('results');
            $xmlInfo->addAttribute('ENDPOINT_VERSION', self::ENDPOINT_VERSION);

            foreach ($results as $row) {
                $resultNode = $xmlResults->addChild('result');
                foreach ($row as $key => $value) {
                    $resultNode[$key] = is_scalar($value) ? (string) $value : '';
                }
            }

            $xmlString = $xml->asXML();
            return $this->xmlResponse($response, $xmlString !== false ? $xmlString : '');
        }

        // HTML
        $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $this->htmlResponse($response, '<pre>' . htmlspecialchars($json !== false ? $json : '{}') . '</pre>');
    }
}
