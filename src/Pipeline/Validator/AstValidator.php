<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Validator;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\Tokenizer\Token;
use BibleGet\Api\Pipeline\Transform\PsalmNumbering;
use BibleGet\Api\Pipeline\Tokenizer\TokenType;
use BibleGet\Api\Util\StringUtils;

/**
 * Validates a parsed BibleQuery AST against database metadata.
 *
 * Checks:
 * - Book name exists and is valid for the requested versions
 * - Chapter numbers are within bounds for the book
 * - Verse numbers are within bounds for the chapter (per version)
 * - Range start <= range end
 * - Cross-chapter range chapters are in order
 *
 * A Psalm reference is checked against each version as that version stores it
 * (PsalmNumbering): "Salmo 131 (132), 6-7" is Hebrew 132 in CEI2008 and
 * Vulgate 131 in VGCL, and each must exist where it is looked up. With edition numbering
 * (numbering=edition) a Psalm number is the edition's own: it is checked as written against
 * each edition's index, and the Hebrew verse limits do not apply.
 */
final class AstValidator
{
    /** @var array<int, array<int, array<int, string>>> */
    private array $bibleBooks;

    /**
     * $bibleBooks with diacritics folded, built lazily on the first lookup miss.
     *
     * @var array<int, array<int, array<int, string>>>|null
     */
    private ?array $foldedBibleBooks = null;

    /** @var array<string, array{abbreviations: array<int, string>, biblebooks: array<int, string>, chapter_limit: array<int, int>, verse_limit: array<int, array<int, int>>, book_num: array<int, int>, prologue?: array<int, int>}> */
    private array $indexes;

    /** @var array<int, string> */
    private array $requestedVersions;

    /** @var ValidationError[] */
    private array $errors = [];

    /** @var array<int, string> */
    private array $validatedVariants = [];

    private PsalmNumbering $psalmNumbering;

    /** Psalm numbers are each edition's own (numbering=edition), not Hebrew. */
    private bool $editionNumbering;

    /**
     * @param array<int, array<int, array<int, string>>> $bibleBooks
     * @param array<string, array{abbreviations: array<int, string>, biblebooks: array<int, string>, chapter_limit: array<int, int>, verse_limit: array<int, array<int, int>>, book_num: array<int, int>, prologue?: array<int, int>}> $indexes
     * @param array<int, string> $requestedVersions
     * @param bool $editionNumbering Psalm numbers are each edition's own (numbering=edition).
     */
    public function __construct(array $bibleBooks, array $indexes, array $requestedVersions, bool $editionNumbering = false)
    {
        $this->bibleBooks        = $bibleBooks;
        $this->indexes           = $indexes;
        $this->requestedVersions = $requestedVersions;
        $this->psalmNumbering    = new PsalmNumbering();
        $this->editionNumbering  = $editionNumbering;
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

        $resolvedQuery = new BibleQuery($nonZeroBookIdx, $query->segments);
        if ($this->editionNumbering) {
            $this->validateEditionReadings($resolvedQuery, $nonZeroBookIdx);
        } else {
            // Shape (numbers >= 1, ranges in order), once, on the reference as written
            // with Psalms read as Hebrew numbers; then bounds, per version, on the
            // reference as that version stores it.
            $this->validateShape($this->stored($resolvedQuery, ''));
            if (empty($this->errors)) {
                foreach ($this->validatedVariants as $variant) {
                    $this->validateBounds($this->stored($resolvedQuery, $variant), $nonZeroBookIdx, $variant);
                }
            }
        }

        if (!empty($this->errors)) {
            return null;
        }

        // Return a new BibleQuery with the resolved book index
        return new BibleQuery($nonZeroBookIdx, $query->segments);
    }

    /**
     * With numbering=edition each version reads a two-number psalm as the number it prints
     * (Vulgate for VGCL and DRB, Hebrew otherwise), so a range runs forward or backward in
     * that version's own numbering: shape on each version's reading, then (if every reading
     * has a valid shape) bounds on each. A shape error that several readings share (any
     * non-Psalm reference) is reported once.
     */
    private function validateEditionReadings(BibleQuery $query, int $nonZeroBookIdx): void
    {
        $readings    = [];
        $shapeErrors = [];
        foreach ($this->validatedVariants as $variant) {
            $readings[$variant] = $this->stored($query, $variant);
            $this->validateShape($readings[$variant]);
            foreach ($this->errors as $error) {
                $shapeErrors[$error->message . "\0" . $error->position] = $error;
            }
            $this->errors = [];
        }
        $this->errors = array_values($shapeErrors);
        if (empty($this->errors)) {
            foreach ($readings as $variant => $stored) {
                $this->validateBounds($stored, $nonZeroBookIdx, $variant);
            }
        }
    }

    /**
     * The reference as $variant stores it ('' for the Hebrew reading): Psalms resolved from
     * Hebrew numbers, or with numbering=edition, taken as the edition's own numbers.
     */
    private function stored(BibleQuery $query, string $variant): BibleQuery
    {
        return $this->editionNumbering
            ? $this->psalmNumbering->editionChapters($query, $variant)
            : $this->psalmNumbering->resolve($query, $variant);
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
     *
     * Two passes: an exact match on the normalized token first, and only if
     * that finds nothing, a retry with diacritics folded on both sides so
     * that e.g. Greek typed in capitals (which drops accents) still resolves.
     * Exact always wins, so scripts where a mark is phonemic (Arabic hamza,
     * Japanese dakuten, Vietnamese tones) keep their distinctions. In either
     * pass a token claimed by several books resolves to the lowest book
     * number (#147).
     */
    private function resolveBookIndex(string $bookName): ?int
    {
        $idx = QuoteContext::idxOf($bookName, $this->bibleBooks);
        if ($idx === false) {
            $idx = QuoteContext::idxOf(StringUtils::foldDiacritics($bookName), $this->foldedBibleBooks());
        }
        return $idx === false ? null : $idx;
    }

    /**
     * @return array<int, array<int, array<int, string>>>
     */
    private function foldedBibleBooks(): array
    {
        if ($this->foldedBibleBooks === null) {
            $this->foldedBibleBooks = array_map(
                static fn(array $languages): array => array_map(
                    static fn(array $variants): array => array_map([StringUtils::class, 'foldDiacritics'], $variants),
                    $languages
                ),
                $this->bibleBooks
            );
        }
        return $this->foldedBibleBooks;
    }

    /**
     * Numbers are 1 or greater (a chapter may be 0, a book's prologue: validateChapter checks that the edition has one),
     * every range runs forward, and a verse of a psalm that the
     * Vulgate splits or joins exists in the Hebrew numbering ($query is read as Hebrew).
     */
    private function validateShape(BibleQuery $query): void
    {
        foreach ($query->segments as $segment) {
            $refs = $segment instanceof VerseRange ? [$segment->from, $segment->to] : [$segment];
            foreach ($refs as $ref) {
                if ($ref->chapter < 0) {
                    $this->errors[] = new ValidationError(
                        sprintf('Invalid chapter number: %d. Chapters must be 1 or greater (0 for a book\'s prologue).', $ref->chapter),
                        $ref->position
                    );
                } elseif ($ref->verse !== null && $ref->verse < 1) {
                    $this->errors[] = new ValidationError(
                        sprintf('Invalid verse number: %d. Verses must be 1 or greater.', $ref->verse),
                        $ref->position
                    );
                } elseif (!$this->editionNumbering && $query->book === PsalmNumbering::PSALMS_BOOK_NUM && $ref->verse !== null) {
                    $limit = PsalmNumbering::hebrewVerseLimit($ref->chapter);
                    if ($limit !== null && $ref->verse > $limit) {
                        $this->errors[] = new ValidationError(
                            sprintf(
                                'A verse in the query is out of bounds: there is no verse <%d> in Psalm <%d> in the Hebrew numbering, the last possible verse is <%d>',
                                $ref->verse,
                                $ref->chapter,
                                $limit
                            ),
                            $ref->position
                        );
                    }
                }
            }
            if ($segment instanceof VerseRange) {
                $this->validateRangeOrder($segment);
            }
        }
    }

    private function validateRangeOrder(VerseRange $range): void
    {
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

    /**
     * Every chapter and verse exists in $variant.
     */
    private function validateBounds(BibleQuery $query, int $nonZeroBookIdx, string $variant): void
    {
        foreach ($query->segments as $segment) {
            $refs = $segment instanceof VerseRange ? [$segment->from, $segment->to] : [$segment];
            foreach ($refs as $ref) {
                $this->validateChapter($ref->chapter, $nonZeroBookIdx, $variant, $ref->position);
                if ($ref->verse !== null) {
                    $this->validateVerse($ref->verse, $ref->chapter, $nonZeroBookIdx, $variant, $ref->position);
                }
            }
        }
    }

    private function validateChapter(int $chapter, int $nonZeroBookIdx, string $variant, int $position = -1): void
    {
        if (!isset($this->indexes[$variant])) {
            return;
        }
        $index   = $this->indexes[$variant];
        $bookidx = array_search($nonZeroBookIdx, $index['book_num'], true);
        if ($bookidx === false) {
            return;
        }
        if ($chapter === 0) {
            // A book's prologue (Sirach's): only where the edition's index says it has one.
            if (( $index['prologue'][$bookidx] ?? 0 ) < 1) {
                $this->errors[] = new ValidationError(
                    sprintf('The book has no prologue (chapter 0) in the requested version %s', $variant),
                    $position
                );
            }
            return;
        }
        $chapterLimit = $index['chapter_limit'][$bookidx] ?? 0;
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

    private function validateVerse(int $verse, int $chapter, int $nonZeroBookIdx, string $variant, int $position = -1): void
    {
        if (!isset($this->indexes[$variant])) {
            return;
        }
        $index   = $this->indexes[$variant];
        $bookidx = array_search($nonZeroBookIdx, $index['book_num'], true);
        if ($bookidx === false) {
            return;
        }
        $chapterLimit = $index['chapter_limit'][$bookidx];
        if ($chapter > $chapterLimit) {
            // Already reported by validateChapter
            return;
        }
        $verseLimits = $index['verse_limit'][$bookidx] ?? [];
        // Chapter 0 is the prologue: as many verses as the index gives it (none: already reported by validateChapter).
        $verseLimit = $chapter === 0 ? ( $index['prologue'][$bookidx] ?? 0 ) : ( $verseLimits[$chapter - 1] ?? 0 );
        if ($chapter === 0 && $verseLimit < 1) {
            return;
        }
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
