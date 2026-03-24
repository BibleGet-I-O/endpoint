<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Util\StringUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class KeywordSearchHandler extends AbstractHandler
{
    private const ENDPOINT_VERSION = '3.0';

    private const VALID_MATCH_MODES = ['fulltext', 'exact', 'boolean'];

    /** @var list<string>|null */
    private static ?array $cachedValidVersions = null;

    /** @var array<string, string>|null */
    private static ?array $cachedVersionLanguages = null;

    private LoggerInterface $logger;

    /**
     * Reset cached data (for testing only).
     */
    public static function resetCache(): void
    {
        self::$cachedValidVersions    = null;
        self::$cachedVersionLanguages = null;
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

        [$keyword, $version, $matchMode] = $this->extractSearchParams($params);

        $pdo          = Connection::getConnection();
        $version      = $this->validateVersion($pdo, $version);
        $tsLanguage   = $this->getVersionLanguage($pdo, $version);
        $versionIndex = $this->loadVersionIndex($pdo, $version);

        $searchResult = $this->executeSearch($pdo, $version, $keyword, $matchMode, $tsLanguage);
        $results      = $this->mapSearchResults($searchResult, $version, $versionIndex);

        $body          = new \stdClass();
        $body->results = $results;
        $body->errors  = [];
        $body->info    = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        $response = $this->buildSearchResponse($response, $contentType, $body, $results);
        return $this->withCacheHeaders($request, $response);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, string, string}
     */
    private function extractSearchParams(array $params): array
    {
        $keywordRaw = $params['keyword'] ?? '';
        $keyword    = is_string($keywordRaw) ? $keywordRaw : '';
        $versionRaw = $params['version'] ?? '';
        $version    = is_string($versionRaw) ? $versionRaw : '';

        // Resolve match mode: explicit `match` param takes precedence over legacy `exactmatch`
        $matchRaw = $params['match'] ?? '';
        $match    = is_string($matchRaw) ? strtolower($matchRaw) : '';

        if ($match === '') {
            // Legacy backward compatibility: exactmatch=true → match=exact
            $exactmatchRaw = $params['exactmatch'] ?? false;
            $exactmatch    = match (true) {
                is_bool($exactmatchRaw)   => $exactmatchRaw,
                is_string($exactmatchRaw) => filter_var($exactmatchRaw, FILTER_VALIDATE_BOOLEAN),
                default                   => false,
            };
            $match = $exactmatch ? 'exact' : 'fulltext';
        }

        if ($keyword === '') {
            throw new ValidationException('The keyword parameter is required.');
        }
        if ($version === '') {
            throw new ValidationException('The version parameter is required.');
        }
        if (!in_array($match, self::VALID_MATCH_MODES, true)) {
            throw new ValidationException(
                'Invalid match mode: ' . $match . '. Valid modes are: ' . implode(', ', self::VALID_MATCH_MODES)
            );
        }

        return [$keyword, $version, $match];
    }

    private function validateVersion(\PDO $pdo, string $version): string
    {
        if (self::$cachedValidVersions === null) {
            self::loadVersionCache($pdo, $this->logger);
        }
        $version = strtoupper($version);
        if (!in_array($version, self::$cachedValidVersions ?? [])) {
            throw new ValidationException('Not a valid version: ' . $version);
        }
        return $version;
    }

    private function getVersionLanguage(\PDO $pdo, string $version): string
    {
        if (self::$cachedVersionLanguages === null) {
            self::loadVersionCache($pdo, $this->logger);
        }
        return self::$cachedVersionLanguages[$version] ?? 'simple';
    }

    private static function loadVersionCache(\PDO $pdo, LoggerInterface $logger): void
    {
        self::$cachedValidVersions    = [];
        self::$cachedVersionLanguages = [];

        $result = $pdo->query('SELECT sigla, ts_language FROM versions_available');
        if ($result === false) {
            $logger->error('Failed to query versions_available');
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        while (is_array($row = $result->fetch(\PDO::FETCH_ASSOC))) {
            $sigla                                = StringUtils::asString($row['sigla']);
            self::$cachedValidVersions[]          = $sigla;
            self::$cachedVersionLanguages[$sigla] = StringUtils::asString($row['ts_language'] ?? 'simple');
        }
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

    private function executeSearch(
        \PDO $pdo,
        string $version,
        string $keyword,
        string $matchMode,
        string $tsLanguage
    ): \PDOStatement {
        $this->assertValidVersionFormat($version);

        // Validate that tsLanguage is a safe identifier (letters only)
        if (!preg_match('/^[a-z]+$/', $tsLanguage)) {
            $tsLanguage = 'simple';
        }

        switch ($matchMode) {
            case 'exact':
                // Escape PostgreSQL regex metacharacters so the keyword is matched literally
                $escapedKeyword = preg_replace('/([.*+?^${}()|[\]\\\\])/', '\\\\\\1', $keyword) ?? $keyword;
                $stmt           = $pdo->prepare(
                    'SELECT * FROM "' . $version . '" WHERE text ~* (\'\y\' || ? || \'\y\') ORDER BY book, chapter, verse'
                );
                $stmt->execute([$escapedKeyword]);
                break;

            case 'boolean':
                $sanitizedKeyword = preg_replace('/[+\-><~*()]+/', '', $keyword) ?? $keyword;
                if (mb_strlen($sanitizedKeyword) < 4) {
                    throw new ValidationException(
                        'Search keyword must be at least 4 characters long (use match=exact for shorter keywords).'
                    );
                }
                try {
                    $stmt = $pdo->prepare(
                        'SELECT * FROM "' . $version . '" WHERE to_tsvector(\'' . $tsLanguage . '\', text) @@ to_tsquery(\'' . $tsLanguage . '\', ?) ORDER BY book, chapter, verse'
                    );
                    $stmt->execute([$keyword]);
                } catch (\PDOException) {
                    throw new ValidationException(
                        'Invalid boolean search expression. Use operators like & (AND), | (OR), ! (NOT).'
                    );
                }
                break;

            default: // fulltext
                $sanitizedKeyword = preg_replace('/[+\-><~*"()]+/', '', $keyword) ?? $keyword;
                if (mb_strlen($sanitizedKeyword) < 4) {
                    throw new ValidationException(
                        'Search keyword must be at least 4 characters long (use match=exact for shorter keywords).'
                    );
                }
                $stmt = $pdo->prepare(
                    'SELECT * FROM "' . $version . '" WHERE to_tsvector(\'' . $tsLanguage . '\', text) @@ websearch_to_tsquery(\'' . $tsLanguage . '\', ?) ORDER BY book, chapter, verse'
                );
                $stmt->execute([$sanitizedKeyword]);
                break;
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
