<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Validator;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\Tokenizer\Token;
use BibleGet\Api\Pipeline\Tokenizer\TokenType;

/**
 * Validates a parsed BibleQuery AST against database metadata.
 *
 * Checks:
 * - Book name exists and is valid for the requested versions
 * - Chapter numbers are within bounds for the book
 * - Verse numbers are within bounds for the chapter (per version)
 * - Range start <= range end
 * - Cross-chapter range chapters are in order
 */
final class AstValidator
{
    /** @var array<int, array<int, array<int, string>>> */
    private array $bibleBooks;

    /** @var array<string, array{abbreviations: array<int, string>, biblebooks: array<int, string>, chapter_limit: array<int, int>, verse_limit: array<int, array<int, int>>, book_num: array<int, int>}> */
    private array $indexes;

    /** @var array<int, string> */
    private array $requestedVersions;

    /** @var ValidationError[] */
    private array $errors = [];

    /** @var array<int, string> */
    private array $validatedVariants = [];

    /**
     * @param array<int, array<int, array<int, string>>> $bibleBooks
     * @param array<string, array{abbreviations: array<int, string>, biblebooks: array<int, string>, chapter_limit: array<int, int>, verse_limit: array<int, array<int, int>>, book_num: array<int, int>}> $indexes
     * @param array<int, string> $requestedVersions
     */
    public function __construct(array $bibleBooks, array $indexes, array $requestedVersions)
    {
        $this->bibleBooks        = $bibleBooks;
        $this->indexes           = $indexes;
        $this->requestedVersions = $requestedVersions;
    }

    /**
     * Validate a BibleQuery AST and resolve the book index.
     *
     * @param Token[] $tokens  The original token stream (for book name + position lookup)
     * @return BibleQuery|null The query with resolved book index, or null if invalid
     */
    public function validate(BibleQuery $query, array $tokens): ?BibleQuery
    {
        $this->errors            = [];
        $this->validatedVariants = [];

        $bookName = $this->extractBookName($tokens);
        if ($bookName === null) {
            $this->errors[] = new ValidationError('No book name found in query');
            return null;
        }

        $bookIdx = $this->resolveBookIndex($bookName);
        if ($bookIdx === null) {
            $position       = $this->extractBookPosition($tokens);
            $this->errors[] = new ValidationError(
                sprintf(
                    'The book %s is not a valid Bible book. Please check the documentation for a list of correct Bible book names, whether full or abbreviated.',
                    $bookName
                ),
                $position
            );
            return null;
        }

        $nonZeroBookIdx = $bookIdx + 1;

        // Determine which requested versions have this book
        foreach ($this->requestedVersions as $variant) {
            if (isset($this->indexes[$variant]) && in_array($nonZeroBookIdx, $this->indexes[$variant]['book_num'], true)) {
                $this->validatedVariants[] = $variant;
            }
        }

        if (empty($this->validatedVariants)) {
            $position       = $this->extractBookPosition($tokens);
            $this->errors[] = new ValidationError(
                sprintf(
                    'The book (index %d) is not available in any of the requested versions: %s',
                    $nonZeroBookIdx,
                    implode(', ', $this->requestedVersions)
                ),
                $position
            );
            return null;
        }

        // Validate each segment
        foreach ($query->segments as $segment) {
            if ($segment instanceof VerseRef) {
                $this->validateVerseRef($segment, $nonZeroBookIdx);
            } elseif ($segment instanceof VerseRange) {
                $this->validateVerseRange($segment, $nonZeroBookIdx);
            }
        }

        if (!empty($this->errors)) {
            return null;
        }

        // Return a new BibleQuery with the resolved book index
        return new BibleQuery($nonZeroBookIdx, $query->segments);
    }

    /**
     * @return ValidationError[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<int, string>
     */
    public function getValidatedVariants(): array
    {
        return $this->validatedVariants;
    }

    /**
     * @param Token[] $tokens
     */
    private function extractBookName(array $tokens): ?string
    {
        $prefix = '';
        $name   = '';
        foreach ($tokens as $token) {
            if ($token->type === TokenType::BOOK_NUMERIC_PREFIX) {
                $prefix = $token->value;
            } elseif ($token->type === TokenType::BOOK_NAME) {
                $name = $token->value;
                break;
            }
        }
        if ($name === '') {
            return null;
        }
        return $prefix . $name;
    }

    /**
     * @param Token[] $tokens
     */
    private function extractBookPosition(array $tokens): int
    {
        foreach ($tokens as $token) {
            if ($token->type === TokenType::BOOK_NUMERIC_PREFIX || $token->type === TokenType::BOOK_NAME) {
                return $token->position;
            }
        }
        return 0;
    }

    /**
     * Resolve a book name to its zero-based index in the BIBLEBOOKS array.
     */
    private function resolveBookIndex(string $bookName): ?int
    {
        $idx = $this->idxOf($bookName);
        if ($idx !== false) {
            return $idx;
        }
        return null;
    }

    /**
     * @return int|false
     */
    private function idxOf(string $needle): int|false
    {
        foreach ($this->bibleBooks as $index => $value) {
            if (is_array($value)) {
                foreach ($value as $subValue) {
                    if (is_array($subValue) && in_array($needle, $subValue, true)) {
                        return $index;
                    }
                }
            }
        }
        return false;
    }

    private function validateVerseRef(VerseRef $ref, int $nonZeroBookIdx): void
    {
        $this->validateChapter($ref->chapter, $nonZeroBookIdx, $ref->position);

        if ($ref->verse !== null) {
            $this->validateVerse($ref->verse, $ref->chapter, $nonZeroBookIdx, $ref->position);
        }
    }

    private function validateVerseRange(VerseRange $range, int $nonZeroBookIdx): void
    {
        $this->validateVerseRef($range->from, $nonZeroBookIdx);
        $this->validateVerseRef($range->to, $nonZeroBookIdx);

        // Check range ordering
        if ($range->from->chapter > $range->to->chapter) {
            $this->errors[] = new ValidationError(
                sprintf(
                    'Invalid range: start chapter %d is greater than end chapter %d',
                    $range->from->chapter,
                    $range->to->chapter
                ),
                $range->to->position
            );
        } elseif (
            $range->from->chapter === $range->to->chapter
            && $range->from->verse !== null
            && $range->to->verse !== null
            && $range->from->verse > $range->to->verse
        ) {
            $this->errors[] = new ValidationError(
                sprintf(
                    'Invalid range: start verse %d is greater than end verse %d in chapter %d',
                    $range->from->verse,
                    $range->to->verse,
                    $range->from->chapter
                ),
                $range->to->position
            );
        }
    }

    private function validateChapter(int $chapter, int $nonZeroBookIdx, int $position = -1): void
    {
        if ($chapter < 1) {
            $this->errors[] = new ValidationError(
                sprintf('Invalid chapter number: %d. Chapters must be 1 or greater.', $chapter),
                $position
            );
            return;
        }

        foreach ($this->validatedVariants as $variant) {
            if (!isset($this->indexes[$variant])) {
                continue;
            }
            $index   = $this->indexes[$variant];
            $bookidx = array_search($nonZeroBookIdx, $index['book_num'], true);
            if ($bookidx === false) {
                continue;
            }
            $chapterLimit = $index['chapter_limit'][$bookidx];
            if ($chapter > $chapterLimit) {
                $this->errors[] = new ValidationError(
                    sprintf(
                        'A chapter in the query is out of bounds: there is no chapter <%d> in the book in the requested version %s, the last possible chapter is <%d>',
                        $chapter,
                        $variant,
                        $chapterLimit
                    ),
                    $position
                );
            }
        }
    }

    private function validateVerse(int $verse, int $chapter, int $nonZeroBookIdx, int $position = -1): void
    {
        if ($verse < 1) {
            $this->errors[] = new ValidationError(
                sprintf('Invalid verse number: %d. Verses must be 1 or greater.', $verse),
                $position
            );
            return;
        }

        foreach ($this->validatedVariants as $variant) {
            if (!isset($this->indexes[$variant])) {
                continue;
            }
            $index   = $this->indexes[$variant];
            $bookidx = array_search($nonZeroBookIdx, $index['book_num'], true);
            if ($bookidx === false) {
                continue;
            }
            $chapterLimit = $index['chapter_limit'][$bookidx];
            if ($chapter > $chapterLimit) {
                // Already reported by validateChapter
                continue;
            }
            $verseLimits = $index['verse_limit'][$bookidx];
            $verseLimit  = $verseLimits[$chapter - 1] ?? 0;
            if ($verse > $verseLimit) {
                $this->errors[] = new ValidationError(
                    sprintf(
                        'A verse in the query is out of bounds: there is no verse <%d> at chapter <%d> in the requested version %s, the last possible verse is <%d>',
                        $verse,
                        $chapter,
                        $variant,
                        $verseLimit
                    ),
                    $position
                );
            }
        }
    }
}
