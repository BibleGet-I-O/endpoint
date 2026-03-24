<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Util\StringUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class SimilarSearchHandler extends AbstractHandler
{
    private const ENDPOINT_VERSION = '3.0';
    private const DEFAULT_LIMIT    = 10;
    private const MAX_LIMIT        = 100;

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

        [$reference, $version, $limit, $crossversion] = $this->extractParams($params);

        $pdo     = Connection::getConnection();
        $version = $this->validateVersion($pdo, $version);
        $this->assertValidVersionFormat($version);

        // Enforce copyright restriction: limit to 30 results for copyrighted versions
        if ($this->isVersionCopyrighted($pdo, $version)) {
            $limit = min($limit, 30);
        }

        // Parse the reference into book abbreviation, chapter, and verse
        [$bookAbbrev, $chapter, $verse] = $this->parseReference($reference);

        // Resolve book number from version index
        $versionIndex = $this->loadVersionIndex($pdo, $version);
        $bookNum      = $this->resolveBookNumber($bookAbbrev, $versionIndex);

        // Look up the source verse's embedding
        $sourceEmbedding = $this->getVerseEmbedding($pdo, $version, $bookNum, $chapter, $verse);

        if ($crossversion) {
            $allVersions = $this->getAllVersions($pdo);
            $results     = $this->searchAcrossVersions($pdo, $allVersions, $sourceEmbedding, $limit, $version, $bookNum, $chapter, $verse);
        } else {
            $results = $this->searchSimilar($pdo, $version, $versionIndex, $sourceEmbedding, $limit, $bookNum, $chapter, $verse);
        }

        $body          = new \stdClass();
        $body->results = $results;
        $body->errors  = [];
        $body->info    = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        $response = $this->buildResponse($response, $contentType, $body, $results);
        return $this->withCacheHeaders($request, $response);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, string, int, bool}
     */
    private function extractParams(array $params): array
    {
        $referenceRaw = $params['reference'] ?? '';
        $reference    = is_string($referenceRaw) ? trim($referenceRaw) : '';

        $versionRaw = $params['version'] ?? '';
        $version    = is_string($versionRaw) ? $versionRaw : '';

        $limitRaw = $params['limit'] ?? self::DEFAULT_LIMIT;
        $limit    = is_numeric($limitRaw) ? (int) $limitRaw : self::DEFAULT_LIMIT;
        $limit    = max(1, min($limit, self::MAX_LIMIT));

        $crossversionRaw = $params['crossversion'] ?? false;
        $crossversion    = match (true) {
            is_bool($crossversionRaw)   => $crossversionRaw,
            is_string($crossversionRaw) => filter_var($crossversionRaw, FILTER_VALIDATE_BOOLEAN),
            default                     => false,
        };

        if ($reference === '') {
            throw new ValidationException('The reference parameter is required.');
        }
        if ($version === '') {
            throw new ValidationException('The version parameter is required.');
        }

        return [$reference, $version, $limit, $crossversion];
    }

    /**
     * Parse a simple Bible reference like "Gen1:1" or "Ps51:3" into components.
     *
     * @return array{string, int, int} [bookAbbrev, chapter, verse]
     */
    private function parseReference(string $reference): array
    {
        // Match patterns like "Gen1:1", "1Sam3:4", "2Kgs5:10", "Ps51:3"
        if (!preg_match('/^(\d?\s*[A-Za-z]+)\s*(\d+)\s*[:,.]\s*(\d+)$/', $reference, $matches)) {
            throw new ValidationException(
                'Invalid reference format: ' . $reference . '. Expected format: Book Chapter:Verse (e.g., Gen1:1, Ps51:3)'
            );
        }

        $bookAbbrev = trim($matches[1]);
        $chapter    = (int) $matches[2];
        $verse      = (int) $matches[3];

        if ($chapter < 1 || $verse < 1) {
            throw new ValidationException('Chapter and verse must be positive integers.');
        }

        return [$bookAbbrev, $chapter, $verse];
    }

    /**
     * Resolve a book abbreviation to its book number using the version index.
     *
     * @param array{abbreviations: list<string>, books: list<string>, book_num: list<string>} $versionIndex
     */
    private function resolveBookNumber(string $bookAbbrev, array $versionIndex): int
    {
        $normalizedAbbrev = strtolower($bookAbbrev);

        foreach ($versionIndex['abbreviations'] as $idx => $abbrev) {
            if (strtolower($abbrev) === $normalizedAbbrev) {
                return (int) $versionIndex['book_num'][$idx];
            }
        }

        // Also try matching against full book names
        foreach ($versionIndex['books'] as $idx => $fullname) {
            if (strtolower($fullname) === $normalizedAbbrev) {
                return (int) $versionIndex['book_num'][$idx];
            }
        }

        throw new ValidationException('Unknown book abbreviation: ' . $bookAbbrev);
    }

    /**
     * Get the embedding vector for a specific verse.
     *
     * @return string The embedding as a pgvector string
     */
    private function getVerseEmbedding(\PDO $pdo, string $version, int $bookNum, int $chapter, int $verse): string
    {
        $stmt = $pdo->prepare(
            'SELECT embedding::text FROM "' . $version . '" WHERE book = ? AND chapter = ? AND verse = ?'
        );
        $stmt->execute([$bookNum, $chapter, $verse]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row) || !isset($row['embedding'])) {
            throw new NotFoundException(
                'Verse not found or has no embedding: ' . $version . ' book=' . $bookNum
                . ' chapter=' . $chapter . ' verse=' . $verse
            );
        }

        return StringUtils::asString($row['embedding']);
    }

    /**
     * @param array{abbreviations: list<string>, books: list<string>, book_num: list<string>} $versionIndex
     * @return array<int, array<string, mixed>>
     */
    private function searchSimilar(
        \PDO $pdo,
        string $version,
        array $versionIndex,
        string $sourceEmbedding,
        int $limit,
        int $excludeBook,
        int $excludeChapter,
        int $excludeVerse
    ): array {
        $sql = 'SELECT *, 1 - (embedding <=> ?::vector) AS similarity '
             . 'FROM "' . $version . '" '
             . 'WHERE embedding IS NOT NULL '
             . 'AND NOT (book = ? AND chapter = ? AND verse = ?) '
             . 'ORDER BY embedding <=> ?::vector '
             . 'LIMIT ?';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sourceEmbedding, $excludeBook, $excludeChapter, $excludeVerse, $sourceEmbedding, $limit]);

        return $this->mapResults($stmt, $version, $versionIndex);
    }

    /**
     * @param list<string> $versions
     * @return array<int, array<string, mixed>>
     */
    private function searchAcrossVersions(
        \PDO $pdo,
        array $versions,
        string $sourceEmbedding,
        int $limit,
        string $sourceVersion,
        int $excludeBook,
        int $excludeChapter,
        int $excludeVerse
    ): array {
        $allResults = [];

        foreach ($versions as $v) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $v)) {
                continue;
            }

            $versionIndex = $this->loadVersionIndex($pdo, $v);

            $isSourceVersion = ( $v === $sourceVersion );
            if ($isSourceVersion) {
                $sql  = 'SELECT *, 1 - (embedding <=> ?::vector) AS similarity '
                     . 'FROM "' . $v . '" '
                     . 'WHERE embedding IS NOT NULL '
                     . 'AND NOT (book = ? AND chapter = ? AND verse = ?) '
                     . 'ORDER BY embedding <=> ?::vector '
                     . 'LIMIT ?';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$sourceEmbedding, $excludeBook, $excludeChapter, $excludeVerse, $sourceEmbedding, $limit]);
            } else {
                $sql  = 'SELECT *, 1 - (embedding <=> ?::vector) AS similarity '
                     . 'FROM "' . $v . '" '
                     . 'WHERE embedding IS NOT NULL '
                     . 'ORDER BY embedding <=> ?::vector '
                     . 'LIMIT ?';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$sourceEmbedding, $sourceEmbedding, $limit]);
            }

            $versionResults = $this->mapResults($stmt, $v, $versionIndex);
            array_push($allResults, ...$versionResults);
        }

        // Sort all results by similarity descending and take top $limit
        usort($allResults, fn($a, $b) => ( $b['similarity'] ?? 0 ) <=> ( $a['similarity'] ?? 0 ));
        return array_slice($allResults, 0, $limit);
    }

    /**
     * @param array{abbreviations: list<string>, books: list<string>, book_num: list<string>} $versionIndex
     * @return array<int, array<string, mixed>>
     */
    private function mapResults(\PDOStatement $stmt, string $version, array $versionIndex): array
    {
        $results = [];
        while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $bookidx = array_search($row['book'], $versionIndex['book_num']);

            $similarity = 0.0;
            if (isset($row['similarity']) && is_numeric($row['similarity']) && is_finite((float) $row['similarity'])) {
                $similarity = round((float) $row['similarity'], 4);
            }

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
                'similarity'  => $similarity,
            ];
            $results[] = $entry;
        }

        return $results;
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

    private function isVersionCopyrighted(\PDO $pdo, string $version): bool
    {
        $stmt = $pdo->prepare('SELECT copyright FROM versions_available WHERE sigla = ?');
        $stmt->execute([$version]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) && ( (int) ( $row['copyright'] ?? 0 ) ) === 1;
    }

    /**
     * @return list<string>
     */
    private function getAllVersions(\PDO $pdo): array
    {
        $result = $pdo->query('SELECT sigla FROM versions_available');
        if ($result === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        $versions = [];
        while (is_array($row = $result->fetch(\PDO::FETCH_ASSOC))) {
            $versions[] = StringUtils::asString($row['sigla']);
        }
        return $versions;
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
            $root = '<?xml version="1.0" encoding="UTF-8"?><BibleGetSimilarSearch/>';
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
