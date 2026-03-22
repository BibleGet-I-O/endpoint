<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

/**
 * Translates validated Bible references into SQL queries.
 * Ported from the legacy QUERY_FORMULATOR class.
 */
class QueryFormulator
{
    private QuoteContext $ctx;
    private int $nn                         = 0;
    private int $i                          = -1;
    private string $currentQuery            = '';
    private string $currentFullQuery        = '';
    private string $currentChapter          = '';
    private string $sqlQuery                = '';
    private int $previousBook               = 0;
    private int $currentBook                = 0;
    private string $currentVariant          = '';
    private string $currentRequestedVariant = '';
    private string $currentPreferOrigin     = '';
    /** @var array<int, string> */
    public array $sqlQueries = [];
    /** @var array<int, string> */
    public array $queriesVersions = [];
    /** @var array<int, string> */
    public array $originalQueries = [];
    /** @var array<int, string> */
    public array $queries = [];

    public function __construct(QuoteContext $ctx)
    {
        $this->ctx     = $ctx;
        $this->queries = $ctx->validatedQueries;
    }

    private static function queryContainsNonConsecutiveVerses(string $query): bool
    {
        return strpos($query, '.') !== false;
    }

    /**
     * @return array<int, string>
     */
    private static function getNonConsecutiveChunks(string $query): array
    {
        return preg_split('/\./', $query) ?: [$query];
    }

    private static function chunkContainsRange(string $chunk): bool
    {
        return strpos($chunk, '-') !== false;
    }

    private static function chunkContainsChapterVerseConstruct(string $chunk): bool
    {
        return strpos($chunk, ',') !== false;
    }

    /**
     * @return array<string, string>
     */
    private static function getRange(string $chunk): array
    {
        $parts = preg_split('/\-/', $chunk) ?: [$chunk, ''];
        return ['from' => $parts[0], 'to' => $parts[1] ?? ''];
    }

    /**
     * @return array<string, string>
     */
    private static function getChapterVerseFromConstruct(string $construct): array
    {
        $parts = preg_split('/,/', $construct) ?: [$construct, ''];
        return ['chapter' => $parts[0], 'verse' => $parts[1] ?? ''];
    }

    /**
     * @return array<int, string>|false
     */
    private function captureBookIndicator(): array|false
    {
        if (QuoteContext::stringWithUpperAndLowerCaseVariants($this->currentQuery)) {
            if (preg_match('/^([1-4]{0,1}((\p{Lu}\p{Ll}*)+))/u', $this->currentQuery, $res)) {
                $this->currentQuery = preg_replace('/^[1-4]{0,1}\p{Lu}\p{Ll}*/u', '', $this->currentQuery) ?? $this->currentQuery;
                return $res;
            }
            return false;
        }
        if (preg_match('/^([1-4]{0,1}((\p{L}\p{M}*)+))/u', $this->currentQuery, $res)) {
            $this->currentQuery = preg_replace('/^[1-4]{0,1}(\p{L}\p{M}*)+/u', '', $this->currentQuery) ?? $this->currentQuery;
            return $res;
        }
        return false;
    }

    private function validateVerseOriginPreference(): void
    {
        $this->currentPreferOrigin = '';
        if ($this->currentBook === 19) {
            if (in_array($this->currentRequestedVariant, $this->ctx->CATHOLIC_VERSIONS)) {
                $preferOrigin              = $this->ctx->DATA['preferorigin'] ?? '';
                $origin                    = in_array($preferOrigin, QuoteContext::ALLOWED_PREFER_ORIGINS, true) ? $preferOrigin : 'GREEK';
                $this->currentPreferOrigin = " AND verseorigin = '" . $origin . "'";
            }
        }
    }

    private function initSQLStatement(): void
    {
        $this->sqlQuery = 'SELECT * FROM ' . $this->currentRequestedVariant . ' WHERE book = ' . (int) $this->currentBook;
    }

    private function setSQLLimit(): void
    {
        if (in_array($this->currentRequestedVariant, $this->ctx->COPYRIGHT_VERSIONS)) {
            $this->sqlQueries[$this->nn] .= ' LIMIT 30';
        }
    }

    private function finalizeQuery(): void
    {
        $this->sqlQueries[$this->nn]     .= $this->currentPreferOrigin;
        $this->queriesVersions[$this->nn] = $this->currentRequestedVariant;
        $this->sqlQueries[$this->nn]     .= ' ORDER BY verseID';
        $this->setSQLLimit();
    }

    /**
     * @param array<int, string> $matchedBook
     */
    private function bestGuessBookIdx(array $matchedBook): int
    {
        $key1 = $this->currentVariant != '' ? array_search($matchedBook[0], $this->ctx->INDEXES[$this->currentVariant]['biblebooks']) : false;
        $key2 = $this->currentVariant != '' ? array_search($matchedBook[0], $this->ctx->INDEXES[$this->currentVariant]['abbreviations']) : false;
        $key3 = QuoteContext::idxOf($matchedBook[0], $this->ctx->BIBLEBOOKS);

        if ($key1 !== false) {
            return $this->ctx->INDEXES[$this->currentVariant]['book_num'][$key1];
        } elseif ($key2 !== false) {
            return $this->ctx->INDEXES[$this->currentVariant]['book_num'][$key2];
        } elseif ($key3 !== false) {
            return $key3 + 1;
        }
        return 0;
    }

    /**
     * @param array<int, string>|false $matchedBook
     */
    private function setBook(array|false $matchedBook): void
    {
        if ($matchedBook) {
            $this->currentBook  = $this->bestGuessBookIdx($matchedBook);
            $this->previousBook = $this->currentBook;
        } else {
            $this->currentBook = $this->previousBook;
        }
    }

    /**
     * @param array<string, string|null> $cvConstructLeft
     * @param array<string, string|null> $cvConstructRight
     */
    private function accountForMultipleChapterDifference(array $cvConstructLeft, array $cvConstructRight): void
    {
        $rightChapter = (int) $cvConstructRight['chapter'];
        $leftChapter  = (int) $cvConstructLeft['chapter'];
        if ($rightChapter - $leftChapter > 1) {
            for ($d = 1; $d < ( $rightChapter - $leftChapter ); $d++) {
                $this->sqlQueries[$this->nn] .= ' OR ( chapter = ' . ( $leftChapter + $d ) . ' )';
            }
        }
    }

    /**
     * Psalm verse-mapping rules for VGCL/DRB versions.
     * Each entry: [[chapter, verseMin, verseMax], ...] => [mappedChapter, mappedVerse]
     * A null verseMin/verseMax means the rule matches when verse is null too.
     *
     * @var array<int, array{ranges: array<int, array{int, int|null, int|null}>, map: array{int, int}}>
     */
    private const PSALM_VGCL_DRB_MAPPINGS = [
        ['ranges' => [[10, 4, 13], [11, 1, 1]],       'map' => [10, 3]],
        ['ranges' => [[11, 2, 12], [12, 1, 6]],       'map' => [1, 1]],
        ['ranges' => [[13, 1, 7]],                     'map' => [3, 13]],
        ['ranges' => [[13, 8, 18], [14, 1, 19]],      'map' => [4, 17]],
        ['ranges' => [[15, 1, 3]],                     'map' => [4, 8]],
        ['ranges' => [[15, 4, 14]],                    'map' => [5, 1]],
        ['ranges' => [[15, 15, 19]],                   'map' => [5, 2]],
        ['ranges' => [[16, null, null]],               'map' => [8, 12]],
    ];

    /**
     * @return array{int, int, string}|null  [chapter, verse, preferorigin] or null if no mapping applies
     */
    private function matchPsalmMapping(int|string|null $chapter, int|string|null $verse): ?array
    {
        foreach (self::PSALM_VGCL_DRB_MAPPINGS as $mapping) {
            foreach ($mapping['ranges'] as [$rngChapter, $rngMin, $rngMax]) {
                if ($chapter != $rngChapter) {
                    continue;
                }
                $verseInRange = $rngMin === null
                    ? $verse === null
                    : $verse !== null && $verse >= $rngMin && $verse <= ( $rngMax ?? $rngMin );
                if ($verseInRange) {
                    return [$mapping['map'][0], $mapping['map'][1], " AND verseorigin = 'GREEK'"];
                }
            }
        }
        return null;
    }

    /**
     * @param-out string $toChapter
     * @param-out string|null $toVerse
     */
    private function mapReference(int|string|null $chapter, int|string|null $verse, string|null &$toChapter, string|null &$toVerse, bool $updatePreferOrigin): void
    {
        $preferorigin = $this->currentPreferOrigin;

        if ($this->isPsalmInVgclDrb()) {
            $mapped = $this->matchPsalmMapping($chapter, $verse);
            if ($mapped !== null) {
                [$chapter, $verse, $preferorigin] = $mapped;
            }
        }

        if ($updatePreferOrigin) {
            $this->currentPreferOrigin = $preferorigin;
        }
        $toChapter = (string) $chapter;
        $toVerse   = $verse !== null ? (string) $verse : null;
    }

    private function isPsalmInVgclDrb(): bool
    {
        $version = $this->currentRequestedVariant;
        $book    = $this->currentBook;
        return in_array($version, $this->ctx->CATHOLIC_VERSIONS)
            && $book === 19
            && ( $version === 'VGCL' || $version === 'DRB' );
    }

    /**
     * @param array<string, string> $range
     */
    private function formulateRangeWithChapterVerse(array $range): void
    {
        $cvConstructLeft      = self::getChapterVerseFromConstruct($range['from']);
        $this->currentChapter = $cvConstructLeft['chapter'];
        $this->mapReference($cvConstructLeft['chapter'], $cvConstructLeft['verse'], $cvConstructLeft['chapter'], $cvConstructLeft['verse'], true);

        if (self::chunkContainsChapterVerseConstruct($range['to'])) {
            $cvConstructRight     = self::getChapterVerseFromConstruct($range['to']);
            $this->currentChapter = $cvConstructRight['chapter'];
            $this->mapReference($cvConstructRight['chapter'], $cvConstructRight['verse'], $cvConstructRight['chapter'], $cvConstructRight['verse'], true);
            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( ( chapter = ' . (int) $cvConstructLeft['chapter'] . ' AND verse >= ' . (int) $cvConstructLeft['verse'] . ' )';
            $this->accountForMultipleChapterDifference($cvConstructLeft, $cvConstructRight);
            $this->sqlQueries[$this->nn] .= ' OR ( chapter = ' . (int) $cvConstructRight['chapter'] . ' AND verse <= ' . (int) $cvConstructRight['verse'] . ' ) )';
        } else {
            $this->mapReference($this->currentChapter, $range['to'], $this->currentChapter, $range['to'], true);
            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND chapter = ' . (int) $cvConstructLeft['chapter'] . ' AND verse >= ' . (int) $cvConstructLeft['verse'] . ' AND verse <= ' . (int) $range['to'];
        }
    }

    private function formulateRangeChunk(string $chunk): void
    {
        $range = self::getRange($chunk);
        if (self::chunkContainsChapterVerseConstruct($range['from'])) {
            $this->formulateRangeWithChapterVerse($range);
        } else {
            $this->mapReference($this->currentChapter, $range['from'], $this->currentChapter, $range['from'], true);
            $unusedChapter = null;
            $this->mapReference($this->currentChapter, $range['to'], $unusedChapter, $range['to'], false);
            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( chapter = ' . (int) $this->currentChapter . ' AND verse >= ' . (int) $range['from'] . ' AND verse <= ' . (int) $range['to'] . ' )';
        }
    }

    private function formulateSingleChunk(string $chunk): void
    {
        if (self::chunkContainsChapterVerseConstruct($chunk)) {
            $cvConstruct          = self::getChapterVerseFromConstruct($chunk);
            $this->currentChapter = $cvConstruct['chapter'];
            $this->mapReference($cvConstruct['chapter'], $cvConstruct['verse'], $cvConstruct['chapter'], $cvConstruct['verse'], true);
            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( chapter = ' . (int) $cvConstruct['chapter'] . ' AND verse = ' . (int) $cvConstruct['verse'] . ' )';
        } else {
            $this->mapReference($this->currentChapter, $chunk, $this->currentChapter, $chunk, true);
            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( chapter = ' . (int) $this->currentChapter . ' AND verse = ' . (int) $chunk . ' )';
        }
    }

    private function formulateNonConsecutiveVerses(): void
    {
        $nonConsecutiveChunks = self::getNonConsecutiveChunks($this->currentQuery);
        foreach ($nonConsecutiveChunks as $chunk) {
            $this->originalQueries[$this->nn] = $this->currentFullQuery;
            if (self::chunkContainsRange($chunk)) {
                $this->formulateRangeChunk($chunk);
            } else {
                $this->formulateSingleChunk($chunk);
            }
            $this->finalizeQuery();
            $this->nn++;
        }
    }

    private function formulateConsecutiveVerses(): void
    {
        $this->originalQueries[$this->nn] = $this->currentFullQuery;

        if (self::chunkContainsRange($this->currentQuery)) {
            $range = self::getRange($this->currentQuery);
            if (self::chunkContainsChapterVerseConstruct($range['from'])) {
                $cvConstructLeft      = self::getChapterVerseFromConstruct($range['from']);
                $this->currentChapter = $cvConstructLeft['chapter'];
                $this->mapReference($cvConstructLeft['chapter'], $cvConstructLeft['verse'], $cvConstructLeft['chapter'], $cvConstructLeft['verse'], true);
                if (self::chunkContainsChapterVerseConstruct($range['to'])) {
                    $cvConstructRight = self::getChapterVerseFromConstruct($range['to']);
                    $this->mapReference($cvConstructRight['chapter'], $cvConstructRight['verse'], $cvConstructRight['chapter'], $cvConstructRight['verse'], true);
                    $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( ( chapter = ' . (int) $cvConstructLeft['chapter'] . ' AND verse >= ' . (int) $cvConstructLeft['verse'] . ' )';
                    $this->accountForMultipleChapterDifference($cvConstructLeft, $cvConstructRight);
                    $this->sqlQueries[$this->nn] .= ' OR ( chapter = ' . (int) $cvConstructRight['chapter'] . ' AND verse <= ' . (int) $cvConstructRight['verse'] . ' ) )';
                } else {
                    $mappedChapter = null;
                    $this->mapReference($cvConstructLeft['chapter'], $range['to'], $mappedChapter, $range['to'], true);
                    $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND chapter = ' . (int) $cvConstructLeft['chapter'] . ' AND verse >= ' . (int) $cvConstructLeft['verse'] . ' AND verse <= ' . (int) $range['to'];
                }
            } else {
                $nullVerse = null;
                $this->mapReference($range['from'], null, $range['from'], $nullVerse, true);
                $this->mapReference($range['to'], null, $range['to'], $nullVerse, false);
                $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND chapter >= ' . (int) $range['from'] . ' AND chapter <= ' . (int) $range['to'];
            }
        } elseif (self::chunkContainsChapterVerseConstruct($this->currentQuery)) {
            $cvConstruct          = self::getChapterVerseFromConstruct($this->currentQuery);
            $this->currentChapter = $cvConstruct['chapter'];
            $this->mapReference($cvConstruct['chapter'], $cvConstruct['verse'], $cvConstruct['chapter'], $cvConstruct['verse'], true);
            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND chapter = ' . (int) $cvConstruct['chapter'] . ' AND verse = ' . (int) $cvConstruct['verse'];
        } else {
            $this->currentChapter = $this->currentQuery;
            $mappedChapter        = null;
            $nullVerse            = null;
            $this->mapReference($this->currentChapter, null, $mappedChapter, $nullVerse, true);
            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND chapter = ' . (int) $mappedChapter;
        }

        $this->finalizeQuery();
        $this->nn++;
    }

    public function formulateSQLQueries(): void
    {
        foreach ($this->ctx->REQUESTED_VERSIONS as $version) {
            $this->i                       = 0;
            $this->currentRequestedVariant = $version;
            foreach ($this->queries as $query) {
                $this->currentQuery     = $query;
                $this->currentFullQuery = $query;
                $this->currentChapter   = '';
                if (!in_array($version, $this->ctx->validatedVariants[$this->i])) {
                    $this->i++;
                    continue;
                }
                $this->currentVariant = $version;

                $matchedBook = $this->captureBookIndicator();
                $this->setBook($matchedBook);
                $this->initSQLStatement();
                $this->validateVerseOriginPreference();

                if (self::queryContainsNonConsecutiveVerses($this->currentQuery)) {
                    $this->formulateNonConsecutiveVerses();
                } else {
                    $this->formulateConsecutiveVerses();
                }
                $this->i++;
            }
        }

        $this->ctx->formulatedQueries  = $this->sqlQueries;
        $this->ctx->originalQueries    = $this->originalQueries;
        $this->ctx->formulatedVariants = $this->queriesVersions;
    }
}
