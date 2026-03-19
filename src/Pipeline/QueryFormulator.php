<?php

namespace BibleGet\Api\Pipeline;

/**
 * Translates validated Bible references into SQL queries.
 * Ported from the legacy QUERY_FORMULATOR class.
 */
class QueryFormulator
{
    private QuoteContext $ctx;
    private int $nn                      = 0;
    private int $i                       = -1;
    private string $currentQuery         = '';
    private string $currentFullQuery     = '';
    private string $currentChapter       = '';
    private string $sqlQuery             = '';
    private string|int $previousBook     = '';
    private string|int $currentBook      = '';
    private string $currentVariant       = '';
    private string $currentRequestedVariant = '';
    private string $currentPreferOrigin  = '';
    /** @var array<int, string> */
    public array $sqlQueries             = [];
    /** @var array<int, string> */
    public array $queriesVersions        = [];
    /** @var array<int, string> */
    public array $originalQueries        = [];
    /** @var array<int, string> */
    public array $queries                = [];

    public function __construct(QuoteContext $ctx)
    {
        $this->ctx = $ctx;
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
        if ($this->currentBook == 19 || $this->currentBook === '19') {
            if (in_array($this->currentRequestedVariant, $this->ctx->CATHOLIC_VERSIONS)) {
                $this->currentPreferOrigin = " AND verseorigin = '" . ($this->ctx->DATA['preferorigin'] != '' ? $this->ctx->DATA['preferorigin'] : 'GREEK') . "'";
            }
        }
    }

    private function initSQLStatement(): void
    {
        $this->sqlQuery = 'SELECT * FROM ' . $this->currentRequestedVariant . ' WHERE book = ' . $this->currentBook;
    }

    private function setSQLLimit(): void
    {
        if (in_array($this->currentRequestedVariant, $this->ctx->COPYRIGHT_VERSIONS)) {
            $this->sqlQueries[$this->nn] .= ' LIMIT 30';
        }
    }

    private function finalizeQuery(): void
    {
        $this->sqlQueries[$this->nn] .= $this->currentPreferOrigin;
        $this->queriesVersions[$this->nn] = $this->currentRequestedVariant;
        $this->sqlQueries[$this->nn] .= ' ORDER BY verseID';
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
            $this->currentBook = $this->bestGuessBookIdx($matchedBook);
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
        $leftChapter = (int) $cvConstructLeft['chapter'];
        if ($rightChapter - $leftChapter > 1) {
            for ($d = 1; $d < ($rightChapter - $leftChapter); $d++) {
                $this->sqlQueries[$this->nn] .= ' OR ( chapter = ' . ($leftChapter + $d) . ' )';
            }
        }
    }

    /**
     * @param-out string $toChapter
     * @param-out string|null $toVerse
     */
    private function mapReference(int|string|null $chapter, int|string|null $verse, string|null &$toChapter, string|null &$toVerse, bool $updatePreferOrigin): void
    {
        $version = $this->currentRequestedVariant;
        $book = $this->currentBook;
        $preferorigin = $this->currentPreferOrigin;

        if (in_array($version, $this->ctx->CATHOLIC_VERSIONS)) {
            if ($book == 19 || $book === '19') {
                if ($version == 'VGCL' || $version == 'DRB') {
                    if (($chapter == 10 && (($verse >= 4 && $verse <= 13) || $verse == null)) || ($chapter == 11 && ($verse == 1 || $verse == null))) {
                        $chapter = 10; $verse = 3; $preferorigin = " AND verseorigin = 'GREEK'";
                    } elseif (($chapter == 11 && (($verse >= 2 && $verse <= 12) || $verse == null)) || ($chapter == 12 && (($verse >= 1 && $verse <= 6) || $verse == null))) {
                        $chapter = 1; $verse = 1; $preferorigin = " AND verseorigin = 'GREEK'";
                    } elseif ($chapter == 13 && (($verse >= 1 && $verse <= 7) || $verse == null)) {
                        $chapter = 3; $verse = 13; $preferorigin = " AND verseorigin = 'GREEK'";
                    } elseif (($chapter == 13 && (($verse >= 8 && $verse <= 18) || $verse == null)) || ($chapter == 14 && (($verse <= 1 && $verse >= 19) || $verse == null))) {
                        $chapter = 4; $verse = 17; $preferorigin = " AND verseorigin = 'GREEK'";
                    } elseif ($chapter == 15 && (($verse >= 1 && $verse <= 3) || $verse == null)) {
                        $chapter = 4; $verse = 8; $preferorigin = " AND verseorigin = 'GREEK'";
                    } elseif ($chapter == 15 && (($verse >= 4 && $verse <= 14) || $verse == null)) {
                        $chapter = 5; $verse = 1; $preferorigin = " AND verseorigin = 'GREEK'";
                    } elseif ($chapter == 15 && (($verse >= 15 && $verse <= 19) || $verse == null)) {
                        $chapter = 5; $verse = 2; $preferorigin = " AND verseorigin = 'GREEK'";
                    } elseif ($chapter == 16) {
                        $chapter = 8; $verse = 12; $preferorigin = " AND verseorigin = 'GREEK'";
                    }
                }
            }
        }
        if ($updatePreferOrigin) {
            $this->currentPreferOrigin = $preferorigin;
        }
        $toChapter = (string) $chapter;
        $toVerse = $verse !== null ? (string) $verse : null;
    }

    public function formulateSQLQueries(): void
    {
        foreach ($this->ctx->REQUESTED_VERSIONS as $version) {
            $this->i = 0;
            $this->currentRequestedVariant = $version;
            foreach ($this->queries as $query) {
                $this->currentQuery = $query;
                $this->currentFullQuery = $query;
                $this->currentChapter = '';
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
                    $nonConsecutiveChunks = self::getNonConsecutiveChunks($this->currentQuery);
                    foreach ($nonConsecutiveChunks as $chunk) {
                        $this->originalQueries[$this->nn] = $this->currentFullQuery;
                        if (self::chunkContainsRange($chunk)) {
                            $range = self::getRange($chunk);
                            if (self::chunkContainsChapterVerseConstruct($range['from'])) {
                                $cvConstructLeft = self::getChapterVerseFromConstruct($range['from']);
                                $this->currentChapter = $cvConstructLeft['chapter'];
                                $this->mapReference($cvConstructLeft['chapter'], $cvConstructLeft['verse'], $cvConstructLeft['chapter'], $cvConstructLeft['verse'], true);
                                if (self::chunkContainsChapterVerseConstruct($range['to'])) {
                                    $cvConstructRight = self::getChapterVerseFromConstruct($range['to']);
                                    $this->currentChapter = $cvConstructRight['chapter'];
                                    $this->mapReference($cvConstructRight['chapter'], $cvConstructRight['verse'], $cvConstructRight['chapter'], $cvConstructRight['verse'], true);
                                    $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( ( chapter = ' . $cvConstructLeft['chapter'] . ' AND verse >= ' . $cvConstructLeft['verse'] . ' )';
                                    $this->accountForMultipleChapterDifference($cvConstructLeft, $cvConstructRight);
                                    $this->sqlQueries[$this->nn] .= ' OR ( chapter = ' . $cvConstructRight['chapter'] . ' AND verse <= ' . $cvConstructRight['verse'] . ' ) )';
                                } else {
                                    $this->mapReference($this->currentChapter, $range['to'], $this->currentChapter, $range['to'], true);
                                    $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( chapter >= ' . $cvConstructLeft['chapter'] . ' AND verse >= ' . $cvConstructLeft['verse'] . ' )';
                                    $this->sqlQueries[$this->nn] .= ' AND ( chapter <= ' . $this->currentChapter . ' AND verse <= ' . $range['to'] . ' )';
                                }
                            } else {
                                $this->mapReference($this->currentChapter, $range['from'], $this->currentChapter, $range['from'], true);
                                $this->mapReference($this->currentChapter, $range['to'], $nullChapter, $range['to'], false);
                                $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( chapter = ' . $this->currentChapter . ' AND verse >= ' . $range['from'] . ' AND verse <= ' . $range['to'] . ' )';
                            }
                        } else {
                            if (self::chunkContainsChapterVerseConstruct($chunk)) {
                                $cvConstruct = self::getChapterVerseFromConstruct($chunk);
                                $this->currentChapter = $cvConstruct['chapter'];
                                $this->mapReference($cvConstruct['chapter'], $cvConstruct['verse'], $cvConstruct['chapter'], $cvConstruct['verse'], true);
                                $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( chapter = ' . $cvConstruct['chapter'] . ' AND verse = ' . $cvConstruct['verse'] . ' )';
                            } else {
                                $this->mapReference($this->currentChapter, $chunk, $this->currentChapter, $chunk, true);
                                $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( chapter = ' . $this->currentChapter . ' AND verse = ' . $chunk . ' )';
                            }
                        }
                        $this->finalizeQuery();
                        $this->nn++;
                    }
                } else {
                    if (self::chunkContainsRange($this->currentQuery)) {
                        $this->originalQueries[$this->nn] = $this->currentFullQuery;
                        $range = self::getRange($this->currentQuery);
                        if (self::chunkContainsChapterVerseConstruct($range['from'])) {
                            $cvConstructLeft = self::getChapterVerseFromConstruct($range['from']);
                            $this->currentChapter = $cvConstructLeft['chapter'];
                            $this->mapReference($cvConstructLeft['chapter'], $cvConstructLeft['verse'], $cvConstructLeft['chapter'], $cvConstructLeft['verse'], true);
                            if (self::chunkContainsChapterVerseConstruct($range['to'])) {
                                $cvConstructRight = self::getChapterVerseFromConstruct($range['to']);
                                $this->mapReference($cvConstructRight['chapter'], $cvConstructRight['verse'], $cvConstructRight['chapter'], $cvConstructRight['verse'], true);
                                $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND ( ( chapter = ' . $cvConstructLeft['chapter'] . ' AND verse >= ' . $cvConstructLeft['verse'] . ' )';
                                $this->accountForMultipleChapterDifference($cvConstructLeft, $cvConstructRight);
                                $this->sqlQueries[$this->nn] .= ' OR ( chapter = ' . $cvConstructRight['chapter'] . ' AND verse <= ' . $cvConstructRight['verse'] . ' ) )';
                            } else {
                                $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND chapter >= ' . $cvConstructLeft['chapter'] . ' AND verse >= ' . $cvConstructLeft['verse'];
                                $this->mapReference($cvConstructLeft['chapter'], $range['to'], $mappedChapter, $range['to'], true);
                                $this->sqlQueries[$this->nn] .= ' AND chapter <= ' . $mappedChapter . ' AND verse <= ' . $range['to'];
                            }
                        } else {
                            $this->mapReference($range['from'], null, $range['from'], $nullVerse, true);
                            $this->mapReference($range['to'], null, $range['to'], $nullVerse, false);
                            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND chapter >= ' . $range['from'] . ' AND chapter <= ' . $range['to'];
                        }
                    } else {
                        if (self::chunkContainsChapterVerseConstruct($this->currentQuery)) {
                            $this->originalQueries[$this->nn] = $this->currentFullQuery;
                            $cvConstruct = self::getChapterVerseFromConstruct($this->currentQuery);
                            $this->currentChapter = $cvConstruct['chapter'];
                            $this->mapReference($cvConstruct['chapter'], $cvConstruct['verse'], $cvConstruct['chapter'], $cvConstruct['verse'], true);
                            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND chapter = ' . $cvConstruct['chapter'] . ' AND verse = ' . $cvConstruct['verse'];
                        } else {
                            $this->originalQueries[$this->nn] = $this->currentFullQuery;
                            $this->currentChapter = $this->currentQuery;
                            $this->mapReference($this->currentChapter, null, $mappedChapter, $nullVerse, true);
                            $this->sqlQueries[$this->nn] = $this->sqlQuery . ' AND chapter = ' . $mappedChapter;
                        }
                    }
                    $this->finalizeQuery();
                    $this->nn++;
                }
                $this->i++;
            }
        }

        $this->ctx->formulatedQueries = $this->sqlQueries;
        $this->ctx->originalQueries = $this->originalQueries;
        $this->ctx->formulatedVariants = $this->queriesVersions;
    }
}
