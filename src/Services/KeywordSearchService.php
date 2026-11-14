<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Util\StringUtils;
use Psr\Log\LoggerInterface;

/**
 * Keyword (lexical) retrieval over the `text_plain` column: version and
 * `ts_language` validation, the per-mode SQL, and row mapping.
 *
 * Shared by `/v3/search/keyword` (KeywordSearchHandler) and the keyword leg
 * of `/v3/search/hybrid` (HybridSearchHandler).
 */
class KeywordSearchService
{
    /** Minimum sanitized keyword length for the `fulltext` and `boolean` modes. */
    public const MIN_KEYWORD_LENGTH = 4;

    public const SHORT_KEYWORD_MESSAGE = 'Search keyword must be at least 4 characters long (use match=exact for shorter keywords).';

    /** @var list<string>|null */
    private static ?array $cachedValidVersions = null;

    /** @var array<string, string>|null */
    private static ?array $cachedVersionLanguages = null;

    public function __construct(private \PDO $pdo, private LoggerInterface $logger)
    {
    }

    /**
     * Reset cached data (for testing only).
     */
    public static function resetCache(): void
    {
        self::$cachedValidVersions    = null;
        self::$cachedVersionLanguages = null;
    }

    /**
     * Strip the characters the `fulltext` mode ignores. The result is what
     * is sent to `websearch_to_tsquery` and checked against
     * MIN_KEYWORD_LENGTH.
     */
    public static function sanitizeFulltextKeyword(string $keyword): string
    {
        return preg_replace('/[+\-><~*"()]+/', '', $keyword) ?? $keyword;
    }

    /**
     * Whether $keyword is long enough for the `fulltext` mode.
     */
    public static function isFulltextKeywordLongEnough(string $keyword): bool
    {
        return mb_strlen(self::sanitizeFulltextKeyword($keyword)) >= self::MIN_KEYWORD_LENGTH;
    }

    /**
     * Validate each requested version (exists in `versions_available`,
     * well-formed identifier) and resolve the single `ts_language` they
     * share.
     *
     * Keyword search is language-bound: querying English text against an
     * Italian dictionary returns nothing of value. Mixed-language requests
     * are rejected so the failure mode is a clear 4xx rather than silent
     * zero results.
     *
     * @param list<string> $versions
     * @param string $endpoint Endpoint path named in the mixed-language error.
     * @return array{list<string>, string} [validated versions, ts_language]
     * @throws ValidationException
     */
    public function validateVersions(array $versions, string $endpoint): array
    {
        $validatedVersions = [];
        foreach ($versions as $v) {
            $validatedVersions[] = $this->validateVersion($v);
        }

        $tsLanguagesByVersion = [];
        foreach ($validatedVersions as $v) {
            $tsLanguagesByVersion[$v] = $this->getVersionLanguage($v);
        }
        $distinctLanguages = array_values(array_unique(array_values($tsLanguagesByVersion)));
        if (count($distinctLanguages) > 1) {
            throw new ValidationException(
                'Cannot mix versions with different ts_language on ' . $endpoint . ': '
                . implode(', ', array_map(
                    static fn(string $v): string => $v . ' (' . $tsLanguagesByVersion[$v] . ')',
                    $validatedVersions
                ))
            );
        }

        return [$validatedVersions, $distinctLanguages[0] ?? 'simple'];
    }

    private function validateVersion(string $version): string
    {
        if (self::$cachedValidVersions === null) {
            $this->loadVersionCache();
        }
        $version = strtoupper($version);
        if (!in_array($version, self::$cachedValidVersions ?? [])) {
            throw new ValidationException('Not a valid version: ' . $version);
        }
        $this->assertValidVersionFormat($version);
        return $version;
    }

    private function getVersionLanguage(string $version): string
    {
        if (self::$cachedVersionLanguages === null) {
            $this->loadVersionCache();
        }
        return self::$cachedVersionLanguages[$version] ?? 'simple';
    }

    private function loadVersionCache(): void
    {
        self::$cachedValidVersions    = [];
        self::$cachedVersionLanguages = [];

        $result = $this->pdo->query('SELECT sigla, ts_language FROM versions_available');
        if ($result === false) {
            $this->logger->error('Failed to query versions_available');
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
     * Run the keyword query for one version.
     *
     * With $limit null (the `/search/keyword` contract) every match is
     * returned in canonical (`verseID`) order. With a $limit (`fulltext`
     * mode only — the hybrid keyword leg) the top $limit matches by
     * `ts_rank_cd` are returned, ordered by score DESC then `verseID`, so
     * the row order is the leg's rank order.
     *
     * @throws ValidationException on a too-short keyword or a query error
     */
    public function executeSearch(
        string $version,
        string $keyword,
        string $matchMode,
        string $tsLanguage,
        ?int $limit = null
    ): \PDOStatement {
        $this->assertValidVersionFormat($version);

        // Validate that tsLanguage is a safe identifier (letters only)
        if (!preg_match('/^[a-z]+$/', $tsLanguage)) {
            $tsLanguage = 'simple';
        }

        if ($limit !== null && $matchMode !== 'fulltext') {
            throw new \InvalidArgumentException('A ranked limit is only supported for match=fulltext.');
        }

        switch ($matchMode) {
            case 'exact':
                // Escape PostgreSQL regex metacharacters so the keyword is matched literally.
                // No relevance signal in regex matches → score is null per row.
                $escapedKeyword = preg_replace('/([.*+?^${}()|[\]\\\\])/', '\\\\\\1', $keyword) ?? $keyword;
                try {
                    $stmt = $this->pdo->prepare(
                        'SELECT *, NULL::float8 AS score FROM "' . $version . '" '
                        . 'WHERE text_plain ~* (\'\y\' || ? || \'\y\') ORDER BY "verseID"'
                    );
                    $stmt->execute([$escapedKeyword]);
                } catch (\PDOException) {
                    throw new ValidationException(
                        'Search failed for keyword in version ' . $version . '. Please try a different keyword.'
                    );
                }
                break;

            case 'boolean':
                $sanitizedKeyword = preg_replace('/[+\-><~*()]+/', '', $keyword) ?? $keyword;
                if (mb_strlen($sanitizedKeyword) < self::MIN_KEYWORD_LENGTH) {
                    throw new ValidationException(self::SHORT_KEYWORD_MESSAGE);
                }
                try {
                    $stmt = $this->pdo->prepare(
                        'SELECT *, '
                        . 'ts_rank_cd(to_tsvector(\'' . $tsLanguage . '\', text_plain), to_tsquery(\'' . $tsLanguage . '\', ?)) AS score '
                        . 'FROM "' . $version . '" '
                        . 'WHERE to_tsvector(\'' . $tsLanguage . '\', text_plain) @@ to_tsquery(\'' . $tsLanguage . '\', ?) '
                        . 'ORDER BY "verseID"'
                    );
                    $stmt->execute([$keyword, $keyword]);
                } catch (\PDOException) {
                    throw new ValidationException(
                        'Invalid boolean search expression. Use operators like & (AND), | (OR), ! (NOT).'
                    );
                }
                break;

            default: // fulltext
                $sanitizedKeyword = self::sanitizeFulltextKeyword($keyword);
                if (mb_strlen($sanitizedKeyword) < self::MIN_KEYWORD_LENGTH) {
                    throw new ValidationException(self::SHORT_KEYWORD_MESSAGE);
                }
                $orderBy = $limit === null
                    ? 'ORDER BY "verseID"'
                    : 'ORDER BY score DESC, "verseID" LIMIT ?';
                $bind    = [$sanitizedKeyword, $sanitizedKeyword];
                if ($limit !== null) {
                    $bind[] = $limit;
                }
                try {
                    $stmt = $this->pdo->prepare(
                        'SELECT *, '
                        . 'ts_rank_cd(to_tsvector(\'' . $tsLanguage . '\', text_plain), websearch_to_tsquery(\'' . $tsLanguage . '\', ?)) AS score '
                        . 'FROM "' . $version . '" '
                        . 'WHERE to_tsvector(\'' . $tsLanguage . '\', text_plain) @@ websearch_to_tsquery(\'' . $tsLanguage . '\', ?) '
                        . $orderBy
                    );
                    $stmt->execute($bind);
                } catch (\PDOException) {
                    throw new ValidationException(
                        'Full-text search failed. Please try a different keyword.'
                    );
                }
                break;
        }

        return $stmt;
    }

    /**
     * Map raw verse rows into the public search-result row shape. Rows whose
     * book is missing from the version index are logged and skipped.
     *
     * @param array{abbreviations: list<string>, books: list<string>, book_num: list<string>} $versionIndex
     * @return array<int, array<string, mixed>>
     */
    public function mapSearchResults(\PDOStatement $searchResult, string $version, array $versionIndex): array
    {
        $results = [];
        while (is_array($row = $searchResult->fetch(\PDO::FETCH_ASSOC))) {
            $universal_booknum = $row['book'];
            $bookidx           = array_search($row['book'], $versionIndex['book_num']);
            if ($bookidx === false) {
                $this->logger->error('Unmapped book number ' . ( is_scalar($row['book']) ? (string) $row['book'] : 'unknown' ) . ' in version index for search result');
                continue;
            }
            $score     = isset($row['score']) && is_numeric($row['score']) && is_finite((float) $row['score'])
                ? round((float) $row['score'], 4)
                : null;
            $entry     = [
                'version'     => $version,
                'testament'   => is_numeric($row['testament']) ? (int) $row['testament'] : 0,
                'text'        => StringUtils::asString($row['text'] ?? ''),
                'text_plain'  => StringUtils::asString($row['text_plain'] ?? ''),
                'bookabbrev'  => $versionIndex['abbreviations'][$bookidx] ?? '',
                'booknum'     => (int) $bookidx,
                'univbooknum' => $universal_booknum,
                'book'        => $versionIndex['books'][$bookidx] ?? '',
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
}
