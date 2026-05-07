<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Util\SearchUtils;
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

        [$reference, $versions, $limit] = $this->extractParams($params);

        $pdo = Connection::getConnection();

        $validatedVersions = [];
        foreach ($versions as $v) {
            $validated = $this->validateVersion($pdo, $v);
            $this->assertValidVersionFormat($validated);
            $validatedVersions[] = $validated;
        }

        // The first version supplies the source verse's embedding; all listed
        // versions are then searched. The source verse is excluded from results
        // across every target version (verses share book/chapter/verse across
        // the canonical books).
        $sourceVersion = $validatedVersions[0];

        // Parse the reference into book abbreviation, chapter, and verse
        [$bookAbbrev, $chapter, $verse] = $this->parseReference($reference);

        // Resolve book number from the source version's index
        $sourceVersionIndex = $this->loadVersionIndex($pdo, $sourceVersion);
        $bookNum            = $this->resolveBookNumber($bookAbbrev, $sourceVersionIndex);

        // Look up the source verse's embedding (from the source version only)
        $sourceEmbedding = $this->getVerseEmbedding($pdo, $sourceVersion, $bookNum, $chapter, $verse);

        // Reject mixed-model embeddings: skip target versions whose embeddings
        // were computed with a different model than the source's.
        $sourceModel = $this->getEmbeddingModel($pdo, $sourceVersion);

        $allResults = [];
        foreach ($validatedVersions as $v) {
            if ($v !== $sourceVersion && $sourceModel !== '') {
                $vModel = $this->getEmbeddingModel($pdo, $v);
                if ($vModel !== '' && $vModel !== $sourceModel) {
                    $this->logger->info(
                        'Skipping version ' . $v . ' in similar search: embedding model mismatch ('
                        . $vModel . ' vs ' . $sourceModel . ')'
                    );
                    continue;
                }
            }

            $versionIndex = ( $v === $sourceVersion )
                ? $sourceVersionIndex
                : $this->loadVersionIndex($pdo, $v);

            // Per-target copyright restriction: cap to 30 for copyrighted versions.
            $versionLimit = $this->isVersionCopyrighted($pdo, $v) ? min($limit, 30) : $limit;

            array_push($allResults, ...$this->searchSimilar(
                $pdo,
                $v,
                $versionIndex,
                $sourceEmbedding,
                $versionLimit,
                $bookNum,
                $chapter,
                $verse
            ));
        }

        // Multi-version: sort globally by score DESC and trim to the user's limit.
        if (count($validatedVersions) > 1) {
            usort($allResults, static fn(array $a, array $b): int => ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 ));
            $allResults = array_slice($allResults, 0, $limit);
        }

        // Per-version 1-based canonical_order (subverse-aware), verseID stripped.
        SearchUtils::assignCanonicalOrder($allResults);

        $body          = new \stdClass();
        $body->results = $allResults;
        $body->errors  = [];
        $body->info    = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        $response = $this->buildResponse($response, $contentType, $body, $allResults);
        return $this->withCacheHeaders($request, $response);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, list<string>, int}
     */
    private function extractParams(array $params): array
    {
        $referenceRaw = $params['reference'] ?? '';
        $reference    = is_string($referenceRaw) ? trim($referenceRaw) : '';

        $limitRaw = $params['limit'] ?? self::DEFAULT_LIMIT;
        $limit    = is_numeric($limitRaw) ? (int) $limitRaw : self::DEFAULT_LIMIT;
        $limit    = max(1, min($limit, self::MAX_LIMIT));

        if ($reference === '') {
            throw new ValidationException('The reference parameter is required.');
        }

        // Comma-separated `version=A,B,C` (single value still accepted). The
        // first version supplies the source verse's embedding; all listed
        // versions are then searched. Replaces the earlier `crossversion=true`
        // boolean (issue #110).
        $versions = SearchUtils::parseVersionsParam($params['version'] ?? '');

        return [$reference, $versions, $limit];
    }

    /**
     * Parse a simple Bible reference like "Gen1:1" or "Ps51:3" into components.
     *
     * @return array{string, int, int} [bookAbbrev, chapter, verse]
     */
    private function parseReference(string $reference): array
    {
        // Match patterns like "Gen1:1", "1Sam3:4", "2Kgs5:10", "Ps51:3", "Song of Songs 1:1"
        if (!preg_match('/^(\d?\s*[A-Za-z]+(?:\s+[A-Za-z]+)*)\s*(\d+)\s*[:,.]\s*(\d+)$/', $reference, $matches)) {
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
        $sql = 'SELECT *, 1 - (embedding <=> ?::vector) AS score '
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
     * @param array{abbreviations: list<string>, books: list<string>, book_num: list<string>} $versionIndex
     * @return array<int, array<string, mixed>>
     */
    private function mapResults(\PDOStatement $stmt, string $version, array $versionIndex): array
    {
        $results = [];
        while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $bookidx = array_search($row['book'], $versionIndex['book_num']);

            $score = isset($row['score']) && is_numeric($row['score']) && is_finite((float) $row['score'])
                ? round((float) $row['score'], 4)
                : 0.0;

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
     * Get the embedding model name for a version from embedding_metadata.
     * Returns empty string if no metadata exists.
     */
    private function getEmbeddingModel(\PDO $pdo, string $version): string
    {
        try {
            $stmt = $pdo->prepare('SELECT model_name FROM embedding_metadata WHERE version_sigla = ?');
            $stmt->execute([$version]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? StringUtils::asString($row['model_name'] ?? '') : '';
        } catch (\PDOException) {
            // embedding_metadata table may not exist yet
            return '';
        }
    }

    private function isVersionCopyrighted(\PDO $pdo, string $version): bool
    {
        $stmt = $pdo->prepare('SELECT copyright FROM versions_available WHERE sigla = ?');
        $stmt->execute([$version]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $copyright = $row['copyright'] ?? 0;
        return is_numeric($copyright) && ( (int) $copyright ) === 1;
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
