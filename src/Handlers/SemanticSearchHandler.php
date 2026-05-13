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
use BibleGet\Api\Util\SearchUtils;
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
    private ?EmbeddingClient $embeddingClient;

    /**
     * @param string[] $requestPathParams
     * @param EmbeddingClient|null $embeddingClient When non-null, used verbatim
     *        (e.g. tests injecting a mock service URL); the `model=` param is
     *        not honoured in that case. When null (production), a model-bound
     *        client is constructed per request from the validated `model=` slug.
     */
    public function __construct(ResponseFactoryInterface $responseFactory, array $requestPathParams = [], ?EmbeddingClient $embeddingClient = null)
    {
        parent::__construct($responseFactory, $requestPathParams);
        $this->embeddingClient = $embeddingClient;
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

        [$query, $versions, $limit, $threshold, $model] = $this->extractParams($params);
        $column                                         = SearchUtils::modelColumn($model);

        $pdo = Connection::getConnection();

        $validatedVersions = [];
        foreach ($versions as $v) {
            $validated = $this->validateVersion($pdo, $v);
            $this->assertValidVersionFormat($validated);
            $validatedVersions[] = $validated;
        }

        // Construct a model-specific client unless one was injected (tests).
        // The injected-client path is the only way to point this handler at a
        // mock service URL, so we honour it as-is and don't second-guess the
        // model it was built for.
        $client = $this->embeddingClient ?? new EmbeddingClient($model);

        // Embed the user's query via the Python microservice (once, reused per version)
        $embedStart = microtime(true);
        try {
            $queryVector = $client->embed($query);
        } catch (ServiceUnavailableException $e) {
            $this->logger->warning('Embedding service unavailable: ' . $e->getMessage());
            throw new ServiceUnavailableException(
                'Semantic search is temporarily unavailable. Use /v3/search/keyword for text-based search.'
            );
        }
        $embedMs = round(( microtime(true) - $embedStart ) * 1000, 1);
        $this->logger->info('Embedding latency: ' . $embedMs . 'ms for query: ' . substr($query, 0, 100) . ' (model=' . $model . ')');

        // Warn if stored embeddings were computed with a different model (per version + column)
        $serviceModel = $client->getLastModel();
        if ($serviceModel !== '') {
            foreach ($validatedVersions as $v) {
                EmbeddingModelValidator::validate($pdo, $v, $serviceModel, $this->logger, $column);
            }
        }

        // Run pgvector cosine similarity per version, then merge globally by
        // score. Cache loadVersionIndex results so each version's *_idx table
        // is queried at most once per request even when version=A,B,C.
        $dbStart        = microtime(true);
        $allResults     = [];
        $versionIndexes = [];
        foreach ($validatedVersions as $v) {
            $versionIndexes[$v] = $this->loadVersionIndex($pdo, $v);
            array_push($allResults, ...$this->executeSimilaritySearch(
                $pdo,
                $v,
                $queryVector,
                $versionIndexes[$v],
                $limit,
                $threshold,
                $column
            ));
        }
        // Global ordering by score DESC across versions, then trim to limit.
        // Null scores (computation failures) sort to the bottom so a genuine
        // 0.0 still outranks them.
        usort($allResults, static function (array $a, array $b): int {
            $sa = $a['score'] ?? null;
            $sb = $b['score'] ?? null;
            if ($sa === null && $sb === null) {
                return 0;
            }
            if ($sa === null) {
                return 1;
            }
            if ($sb === null) {
                return -1;
            }
            return $sb <=> $sa;
        });
        $allResults = array_slice($allResults, 0, $limit);

        // Per-version 1-based canonical_order (subverse-aware), verseID stripped.
        SearchUtils::assignCanonicalOrder($allResults);

        $dbMs = round(( microtime(true) - $dbStart ) * 1000, 1);
        $this->logger->info('Similarity search latency: ' . $dbMs . 'ms (' . count($allResults) . ' results, column=' . $column . ')');

        $body          = new \stdClass();
        $body->results = $allResults;
        $body->errors  = [];
        $body->info    = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION, 'model' => $model];

        $response = $this->buildResponse($response, $contentType, $body, $allResults);
        return $this->withCacheHeaders($request, $response);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, list<string>, int, float, string}
     */
    private function extractParams(array $params): array
    {
        $queryRaw = $params['query'] ?? '';
        $query    = is_string($queryRaw) ? trim($queryRaw) : '';

        $limitRaw = $params['limit'] ?? self::DEFAULT_LIMIT;
        $limit    = is_numeric($limitRaw) ? (int) $limitRaw : self::DEFAULT_LIMIT;
        $limit    = max(1, min($limit, self::MAX_LIMIT));

        $thresholdRaw = $params['threshold'] ?? self::DEFAULT_THRESHOLD;
        $threshold    = is_numeric($thresholdRaw) ? (float) $thresholdRaw : self::DEFAULT_THRESHOLD;
        $threshold    = max(0.0, min($threshold, 1.0));

        if ($query === '') {
            throw new ValidationException('The query parameter is required.');
        }

        // Comma-separated `version=A,B,C` (single value still accepted).
        $versions = SearchUtils::parseVersionsParam($params['version'] ?? '');

        // `model=labse|minilm` — default labse (better cross-lingual results,
        // adopted after the A/B experiment in discussion #107). Clients that
        // hardcoded a MiniLM-calibrated threshold should pass `model=minilm`
        // explicitly until they re-tune.
        $model = SearchUtils::parseModelParam($params['model'] ?? null);

        return [$query, $versions, $limit, $threshold, $model];
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
     * @param array{abbreviations: list<string>, books: list<string>, book_num: list<string>} $versionIndex
     * @param string $column pgvector column to query (one of SearchUtils::EMBEDDING_MODELS values).
     * @return array<int, array<string, mixed>>
     */
    private function executeSimilaritySearch(\PDO $pdo, string $version, array $queryVector, array $versionIndex, int $limit, float $threshold, string $column): array
    {
        // Column name comes from SearchUtils::EMBEDDING_MODELS via modelColumn(),
        // a closed allowlist — but assert format here too so a future map entry
        // can't smuggle SQL through this concatenation.
        if (!preg_match('/^[a-z_]+$/', $column)) {
            throw new InternalServerErrorException('Invalid embedding column identifier.');
        }

        $vectorStr = '[' . implode(',', $queryVector) . ']';

        $sql = 'SELECT *, 1 - (' . $column . ' <=> ?::vector) AS score '
             . 'FROM "' . $version . '" '
             . 'WHERE ' . $column . ' IS NOT NULL '
             . 'AND 1 - (' . $column . ' <=> ?::vector) >= ? '
             . 'ORDER BY ' . $column . ' <=> ?::vector '
             . 'LIMIT ?';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$vectorStr, $vectorStr, $threshold, $vectorStr, $limit]);

        $results = [];
        while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $bookidx = array_search($row['book'], $versionIndex['book_num']);

            // null (rather than 0.0) when the score can't be computed, so a
            // genuine score of 0.0 — orthogonal verses — is distinguishable
            // from "no score". Comparators that order results must treat
            // null as worse than any numeric score.
            $score = isset($row['score']) && is_numeric($row['score']) && is_finite((float) $row['score'])
                ? round((float) $row['score'], 4)
                : null;

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
                'score'       => $score,
                // Carried forward solely so SearchUtils::assignCanonicalOrder
                // can rank rows; unset before the row leaves the handler.
                'verseID'     => is_numeric($row['verseID'] ?? null) ? (int) $row['verseID'] : 0,
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
