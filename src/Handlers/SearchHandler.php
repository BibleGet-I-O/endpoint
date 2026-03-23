<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Util\StringUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class SearchHandler extends AbstractHandler
{
    private const ENDPOINT_VERSION = '3.0';

    /** @var list<string>|null */
    private static ?array $cachedValidVersions = null;
    private LoggerInterface $logger;

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

        [$keyword, $version, $exactmatch] = $this->extractSearchParams($params);

        $pdo          = Connection::getConnection();
        $version      = $this->validateVersion($pdo, $version);
        $versionIndex = $this->loadVersionIndex($pdo, $version);

        $searchResult = $this->executeSearch($pdo, $version, $keyword, $exactmatch);
        $results      = $this->mapSearchResults($searchResult, $version, $versionIndex);

        $body          = new \stdClass();
        $body->results = $results;
        $body->errors  = [];
        $body->info    = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        return $this->buildSearchResponse($response, $contentType, $body, $results);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, string, bool}
     */
    private function extractSearchParams(array $params): array
    {
        $keywordRaw    = $params['keyword'] ?? '';
        $keyword       = is_string($keywordRaw) ? $keywordRaw : '';
        $versionRaw    = $params['version'] ?? '';
        $version       = is_string($versionRaw) ? $versionRaw : '';
        $exactmatchRaw = $params['exactmatch'] ?? false;
        $exactmatch    = match (true) {
            is_bool($exactmatchRaw)   => $exactmatchRaw,
            is_string($exactmatchRaw) => filter_var($exactmatchRaw, FILTER_VALIDATE_BOOLEAN),
            default                   => false,
        };

        if ($keyword === '') {
            throw new ValidationException('The keyword parameter is required.');
        }
        if ($version === '') {
            throw new ValidationException('The version parameter is required.');
        }

        return [$keyword, $version, $exactmatch];
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
     * @return array{abbreviations: list<string>, books: list<string>, book_num: list<string>}
     */
    private function loadVersionIndex(\PDO $pdo, string $version): array
    {
        $this->assertValidVersionFormat($version);
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

    private function executeSearch(\PDO $pdo, string $version, string $keyword, bool $exactmatch): \PDOStatement
    {
        $this->assertValidVersionFormat($version);

        if ($exactmatch) {
            // PostgreSQL word boundary is \y (equivalent to MySQL's \b)
            $stmt = $pdo->prepare(
                'SELECT * FROM "' . $version . '" WHERE text ~* (\'\y\' || ? || \'\y\') ORDER BY book, chapter, verse'
            );
            $stmt->execute([$keyword]);
        } else {
            $sanitizedKeyword = preg_replace('/[+\-><~*"()]+/', '', $keyword) ?? $keyword;
            if (mb_strlen($sanitizedKeyword) < 4) {
                throw new ValidationException('Search keyword must be at least 4 characters long (use exactmatch=true for shorter keywords).');
            }
            // PostgreSQL full-text search: websearch_to_tsquery safely handles raw user input
            $stmt = $pdo->prepare(
                'SELECT * FROM "' . $version . '" WHERE to_tsvector(\'simple\', text) @@ websearch_to_tsquery(\'simple\', ?) ORDER BY book, chapter, verse'
            );
            $stmt->execute([$sanitizedKeyword]);
        }

        return $stmt;
    }

    /**
     * @param array{abbreviations: list<string>, books: list<string>, book_num: list<string>} $versionIndex
     * @return array<int, array<string, mixed>>
     */
    private function mapSearchResults(\PDOStatement $searchResult, string $version, array $versionIndex): array
    {
        $results = [];
        while (is_array($row = $searchResult->fetch(\PDO::FETCH_ASSOC))) {
            $universal_booknum = $row['book'];
            $bookidx           = array_search($row['book'], $versionIndex['book_num']);
            if ($bookidx === false) {
                $this->logger->error('Unmapped book number ' . ( is_scalar($row['book']) ? (string) $row['book'] : 'unknown' ) . ' in version index for search result');
                continue;
            }
            $entry     = [
                'version'     => $version,
                'testament'   => is_numeric($row['testament']) ? (int) $row['testament'] : 0,
                'text'        => StringUtils::asString($row['text'] ?? ''),
                'bookabbrev'  => $versionIndex['abbreviations'][$bookidx] ?? '',
                'booknum'     => (int) $bookidx,
                'univbooknum' => $universal_booknum,
                'book'        => $versionIndex['books'][$bookidx] ?? '',
                'section'     => is_numeric($row['section']) ? (int) $row['section'] : 0,
                'chapter'     => is_numeric($row['chapter']) ? (int) $row['chapter'] : 0,
                'verse'       => is_numeric($row['verse']) ? (int) $row['verse'] : 0,
            ];
            $results[] = $entry;
        }
        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function buildSearchResponse(ResponseInterface $response, string $contentType, \stdClass $body, array $results): ResponseInterface
    {
        if ($contentType === 'application/json') {
            return $this->jsonResponse($response, $body);
        }

        if ($contentType === 'application/xml') {
            $root = '<?xml version="1.0" encoding="UTF-8"?><BibleGetSearch/>';
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
