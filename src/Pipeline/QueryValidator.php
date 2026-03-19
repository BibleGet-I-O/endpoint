<?php

namespace BibleGet\Api\Pipeline;

/**
 * Validates Bible reference syntax and structure.
 * Ported from the legacy QUERY_VALIDATOR class.
 */
class QueryValidator
{
    private const QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR                          = 0;
    private const VALID_CHAPTER_MUST_FOLLOW_BOOK                                      = 1;
    private const VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR         = 3;
    private const VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS                   = 4;
    private const CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS           = 5;
    private const VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS                        = 6;
    private const CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS = 7;
    private const CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES            = 8;

    private QuoteContext $ctx;
    private int|false $bookIdxBase   = -1;
    private int $nonZeroBookIdx      = -1;
    private string $currentBook      = '';
    private string $currentQuery     = '';
    private string $currentFullQuery = '';
    /** @var array<int, string> */
    private array $validatedVariants = [];

    public function __construct(QuoteContext $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * @return array<int, string>|false
     */
    private static function matchBookInQuery(string $query): array|false
    {
        if (QuoteContext::stringWithUpperAndLowerCaseVariants($query)) {
            if (preg_match('/^([1-4]{0,1}((\p{Lu}\p{Ll}*)+))/u', $query, $res)) {
                return $res;
            }
            return false;
        }
        if (preg_match('/^([1-4]{0,1}((\p{L}\p{M}*)+))/u', $query, $res)) {
            return $res;
        }
        return false;
    }

    private static function validateRuleAgainstQuery(int $rule, string $query): bool
    {
        return match ($rule) {
            self::QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR =>
                (bool) (preg_match('/^[1-4]{0,1}\p{Lu}\p{Ll}*/u', $query) || preg_match('/^[1-4]{0,1}(\p{L}\p{M}*)+/u', $query)),
            self::VALID_CHAPTER_MUST_FOLLOW_BOOK =>
                QuoteContext::stringWithUpperAndLowerCaseVariants($query)
                    ? (preg_match('/^[1-3]{0,1}\p{Lu}\p{Ll}*/u', $query) == preg_match('/^[1-3]{0,1}\p{Lu}\p{Ll}*[1-9][0-9]{0,2}/u', $query))
                    : (preg_match('/^[1-3]{0,1}( \p{L}\p{M}* )+/u', $query) == preg_match('/^[1-3]{0,1}(\p{L}\p{M}*)+[1-9][0-9]{0,2}/u', $query)),
            self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR =>
                !(!strpos($query, ',') || strpos($query, ',') > strpos($query, '.')),
            self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS =>
                (preg_match_all('/(?<![0-9])(?=([1-9][0-9]{0,2}\.[1-9][0-9]{0,2}))/', $query) === substr_count($query, '.')),
            self::CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS =>
                (preg_match_all('/[1-9][0-9]{0,2}\,[1-9][0-9]{0,2}/', $query) === substr_count($query, ',')),
            self::VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS =>
                (preg_match_all('/[1-9][0-9]{0,2}\-[1-9][0-9]{0,2}/', $query) === substr_count($query, '-')),
            self::CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS =>
                !(preg_match('/\-[1-9][0-9]{0,2}\,/', $query) && (!preg_match('/\,[1-9][0-9]{0,2}\-/', $query) || preg_match_all('/(?=\,[1-9][0-9]{0,2}\-)/', $query) > preg_match_all('/(?=\-[1-9][0-9]{0,2}\,)/', $query))),
            self::CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES =>
                !(substr_count($query, '-') > 1 && (!strpos($query, '.') || (substr_count($query, '-') - 1 > substr_count($query, '.')))),
            default => false,
        };
    }

    private static function queryContainsNonConsecutiveVerses(string $query): bool
    {
        return strpos($query, '.') !== false;
    }

    /**
     * @param array<int, string>|string $element
     * @return array<int, string>
     */
    private static function forceArray(array|string $element): array
    {
        return is_array($element) ? $element : [$element];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function getAllVersesAfterDiscontinuousVerseIndicator(string $query): array
    {
        if (preg_match_all('/\.([1-9][0-9]{0,2})$/', $query, $discontinuousVerses)) {
            return [
                array_map('strval', $discontinuousVerses[0]),
                array_map('strval', $discontinuousVerses[1]),
            ];
        }
        return [[], []];
    }

    /**
     * @return array<int, string|array<never, never>>
     */
    private static function getVerseAfterChapterVerseSeparator(string $query): array
    {
        if (preg_match('/,([1-9][0-9]{0,2})/', $query, $verse)) {
            return $verse;
        }
        return [[], []];
    }

    private static function chunkContainsChapterVerseConstruct(string $chunk): bool
    {
        return strpos($chunk, ',') !== false;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function getAllChapterIndicators(string $query): array
    {
        if (preg_match_all('/([1-9][0-9]{0,2})\,/', $query, $chapterIndicators)) {
            return [
                array_map('strval', $chapterIndicators[0]),
                array_map('strval', $chapterIndicators[1]),
            ];
        }
        return [[], []];
    }

    /**
     * @param array<int, string>|false $matchedBook
     */
    private function validateAndSetBook(array|false $matchedBook): bool
    {
        if ($matchedBook !== false) {
            $this->currentBook = $matchedBook[0];
            if ($this->validateBibleBook() === false) {
                return false;
            }
            $this->currentQuery = str_replace($this->currentBook, '', $this->currentQuery);
            return true;
        }
        $this->validateBibleBook();
        return true;
    }

    private function validateChapterVerseConstructs(): bool
    {
        $chapterVerseConstructCount = substr_count($this->currentQuery, ',');
        if ($chapterVerseConstructCount > 1) {
            return $this->validateMultipleVerseSeparators();
        } elseif ($chapterVerseConstructCount == 1) {
            $parts = explode(',', $this->currentQuery);
            if (strpos($parts[1], '-')) {
                if ($this->validateRightHandSideOfVerseSeparator($parts) === false) {
                    return false;
                }
            } else {
                if ($this->validateVersesAfterChapterVerseSeparators($parts) === false) {
                    return false;
                }
            }
            $discontinuousVerses = self::getAllVersesAfterDiscontinuousVerseIndicator($this->currentQuery);
            $highverse = array_pop($discontinuousVerses[1]);
            if ($this->highVerseOutOfBounds($highverse, $parts)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int, int> $rules
     */
    private function queryViolatesAnyRuleOf(string $query, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (self::validateRuleAgainstQuery($rule, $query) === false) {
                $this->ctx->addErrorMessage($rule);
                $this->ctx->incrementBadQueryCount();
                return true;
            }
        }
        return false;
    }

    private function isValidBookForVariant(string $variant): bool
    {
        return in_array($this->nonZeroBookIdx, $this->ctx->INDEXES[$variant]['book_num']);
    }

    private function validateBibleBook(): bool
    {
        $this->bookIdxBase = QuoteContext::idxOf($this->currentBook, $this->ctx->BIBLEBOOKS);
        if ($this->bookIdxBase === false) {
            $this->ctx->addErrorMessage(sprintf('The book %s is not a valid Bible book. Please check the documentation for a list of correct Bible book names, whether full or abbreviated.', $this->currentBook));
            $this->ctx->incrementBadQueryCount();
        } else {
            $this->nonZeroBookIdx = $this->bookIdxBase + 1;
            foreach ($this->ctx->REQUESTED_VERSIONS as $variant) {
                if ($this->isValidBookForVariant($variant)) {
                    $this->validatedVariants[] = $variant;
                }
            }
        }
        return $this->bookIdxBase !== false;
    }

    /**
     * @param array<int, array<int, string>> $chapterIndicators
     */
    private function validateChapterIndicators(array $chapterIndicators): bool
    {
        foreach ($chapterIndicators[1] as $chapterIndicator) {
            foreach ($this->ctx->INDEXES as $jkey => $jindex) {
                $bookidx = array_search($this->nonZeroBookIdx, $jindex['book_num']);
                $chapter_limit = $jindex['chapter_limit'][$bookidx];
                if ($chapterIndicator > $chapter_limit) {
                    $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                    $this->ctx->addErrorMessage(sprintf($msg, $chapterIndicator, $this->currentBook, $jkey, $chapter_limit));
                    $this->ctx->incrementBadQueryCount();
                    return false;
                }
            }
        }
        return true;
    }

    private function validateMultipleVerseSeparators(): bool
    {
        if (!strpos($this->currentQuery, '-')) {
            $this->ctx->addErrorMessage('You cannot have more than one comma and not have a dash!');
            $this->ctx->incrementBadQueryCount();
            return false;
        }
        $parts = explode('-', $this->currentQuery);
        if (count($parts) != 2) {
            $this->ctx->addErrorMessage('You seem to have a malformed querystring, there should be only one dash.');
            $this->ctx->incrementBadQueryCount();
            return false;
        }
        foreach ($parts as $part) {
            $pp = array_map('intval', explode(',', $part));
            foreach ($this->ctx->INDEXES as $jkey => $jindex) {
                $bookidx = array_search($this->nonZeroBookIdx, $jindex['book_num']);
                $chapters_verselimit = $jindex['verse_limit'][$bookidx];
                $verselimit = intval($chapters_verselimit[$pp[0] - 1]);
                if ($pp[1] > $verselimit) {
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    $this->ctx->addErrorMessage(sprintf($msg, $pp[1], $this->currentBook, $pp[0], $jkey, $verselimit));
                    $this->ctx->incrementBadQueryCount();
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param array<int, string> $parts
     */
    private function validateRightHandSideOfVerseSeparator(array $parts): bool
    {
        if (preg_match_all('/[,\.][1-9][0-9]{0,2}\-([1-9][0-9]{0,2})/', $this->currentQuery, $matches)) {
            $matches[1] = self::forceArray($matches[1]);
            $highverse = intval(array_pop($matches[1]));
            foreach ($this->ctx->INDEXES as $jkey => $jindex) {
                $bookidx = array_search($this->nonZeroBookIdx, $jindex['book_num']);
                $chapters_verselimit = $jindex['verse_limit'][$bookidx];
                $verselimit = intval($chapters_verselimit[intval($parts[0]) - 1]);
                if ($highverse > $verselimit) {
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    $this->ctx->addErrorMessage(sprintf($msg, $highverse, $this->currentBook, $parts[0], $jkey, $verselimit));
                    $this->ctx->incrementBadQueryCount();
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param array<int, string> $parts
     */
    private function validateVersesAfterChapterVerseSeparators(array $parts): bool
    {
        $versesAfterChapterVerseSeparators = self::getVerseAfterChapterVerseSeparator($this->currentQuery);
        $highverse = intval($versesAfterChapterVerseSeparators[1]);
        foreach ($this->ctx->INDEXES as $jkey => $jindex) {
            $bookidx = array_search($this->nonZeroBookIdx, $jindex['book_num']);
            $chapters_verselimit = $jindex['verse_limit'][$bookidx];
            $verselimit = intval($chapters_verselimit[intval($parts[0]) - 1]);
            if ($highverse > $verselimit) {
                $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                $this->ctx->addErrorMessage(sprintf($msg, $highverse, $this->currentBook, $parts[0], $jkey, $verselimit));
                $this->ctx->incrementBadQueryCount();
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int, string> $parts
     */
    private function highVerseOutOfBounds(int|string|null $highverse, array $parts): bool
    {
        foreach ($this->ctx->INDEXES as $jkey => $jindex) {
            $bookidx = array_search($this->nonZeroBookIdx, $jindex['book_num']);
            $chapters_verselimit = $jindex['verse_limit'][$bookidx];
            $verselimit = intval($chapters_verselimit[intval($parts[0]) - 1]);
            if ($highverse > $verselimit) {
                $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                $this->ctx->addErrorMessage(sprintf($msg, $highverse, $this->currentBook, $parts[0], $jkey, $verselimit));
                $this->ctx->incrementBadQueryCount();
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, string> $chapters
     */
    private function chapterOutOfBounds(array $chapters): bool
    {
        foreach ($chapters as $zchapter) {
            foreach ($this->ctx->INDEXES as $jkey => $jindex) {
                $bookidx = array_search($this->nonZeroBookIdx, $jindex['book_num']);
                $chapter_limit = $jindex['chapter_limit'][$bookidx];
                if (intval($zchapter) > $chapter_limit) {
                    $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                    $this->ctx->addErrorMessage(sprintf($msg, $zchapter, $this->currentBook, $jkey, $chapter_limit));
                    $this->ctx->incrementBadQueryCount();
                    return true;
                }
            }
        }
        return false;
    }

    public function validateQueries(): bool
    {
        if ($this->queryViolatesAnyRuleOf($this->ctx->queries[0], [self::QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR])) {
            return false;
        }

        foreach ($this->ctx->queries as $query) {
            $this->currentFullQuery = $query;
            $this->currentQuery = $query;
            $this->validatedVariants = [];

            if ($this->queryViolatesAnyRuleOf($this->currentQuery, [self::VALID_CHAPTER_MUST_FOLLOW_BOOK])) {
                return false;
            }

            $matchedBook = self::matchBookInQuery($this->currentQuery);
            if ($this->validateAndSetBook($matchedBook) === false) {
                continue;
            }

            if (self::queryContainsNonConsecutiveVerses($this->currentQuery)) {
                $rules = [
                    self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR,
                    self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS,
                ];
                if ($this->queryViolatesAnyRuleOf($this->currentQuery, $rules)) {
                    continue;
                }
            }

            if (self::chunkContainsChapterVerseConstruct($this->currentQuery)) {
                if ($this->queryViolatesAnyRuleOf($this->currentQuery, [self::CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS])) {
                    continue;
                }
                $chapterIndicators = self::getAllChapterIndicators($this->currentQuery);
                if ($this->validateChapterIndicators($chapterIndicators) === false) {
                    continue;
                }
                if ($this->validateChapterVerseConstructs() === false) {
                    continue;
                }
            } else {
                $chapters = explode('-', $this->currentQuery);
                if ($this->chapterOutOfBounds($chapters)) {
                    continue;
                }
            }

            if (strpos($this->currentQuery, '-')) {
                $rules = [
                    self::VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS,
                    self::CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS,
                    self::CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES,
                ];
                if ($this->queryViolatesAnyRuleOf($this->currentQuery, $rules)) {
                    continue;
                }
            }

            $this->ctx->validatedQueries[] = $this->currentFullQuery;
            $this->ctx->validatedVariants[] = $this->validatedVariants;
        }

        return true;
    }
}
