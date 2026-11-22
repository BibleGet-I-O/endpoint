<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Parser;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\Tokenizer\Token;
use BibleGet\Api\Pipeline\Tokenizer\TokenType;

/**
 * Recursive-descent parser that converts a Token[] stream into AST nodes.
 *
 * Grammar (post-tokenization, numbers already classified):
 *
 *   query       → bookRef segments EOF
 *   bookRef     → BOOK_NUMERIC_PREFIX? BOOK_NAME
 *   segments    → segment (EXPLICIT_VERSE_SEPARATOR verseRef)*
 *   segment     → chapterRef (RANGE_SEPARATOR rangeTarget (RANGE_SEPARATOR verse)?)?
 *                 (the trailing "-verse" only after a rangeTarget that names its chapter and verse:
 *                 "1,1-2,1-2" is 1,1-2,2)
 *   chapterRef  → CHAPTER_NUMBER altChapter? (CHAPTER_VERSE_SEPARATOR verse)?
 *   rangeTarget → CHAPTER_NUMBER altChapter? (CHAPTER_VERSE_SEPARATOR verse)?
 *               | verse
 *   verseRef    → verse (RANGE_SEPARATOR verse)?
 *   verse       → VERSE_NUMBER PARTIAL_VERSE_SUFFIX?
 *   altChapter  → OPEN_PARENTHESIS ALTERNATE_CHAPTER CLOSE_PARENTHESIS
 */
final class ReferenceParser
{
    /** @var Token[] */
    private array $tokens;
    private int $pos;
    private int $book;

    /**
     * @param Token[] $tokens
     */
    public function parse(array $tokens): BibleQuery
    {
        $this->tokens = $tokens;
        $this->pos    = 0;
        $this->book   = 0;

        $this->parseBookRef();
        $segments = $this->parseSegments();

        // Reject trailing tokens that were not consumed by the grammar
        if (!$this->check(TokenType::EOF)) {
            $token = $this->current();
            throw new ParseException(
                sprintf('Unexpected token %s at position %d', $token->type->value, $token->position),
                $token->position
            );
        }

        return new BibleQuery($this->book, $segments);
    }

    private function parseBookRef(): void
    {
        // The book index is not resolved here — we store 0 as a placeholder.
        // The validator resolves the book name to its numeric index.
        if ($this->check(TokenType::BOOK_NUMERIC_PREFIX)) {
            $this->advance(); // consume prefix
        }

        if ($this->check(TokenType::BOOK_NAME)) {
            $this->advance();
        }
        // If no book name found, book stays at 0 (inherited from previous query)
    }

    /**
     * @return array<VerseRef|VerseRange>
     */
    private function parseSegments(): array
    {
        $segments = [];

        // First segment must start with a chapter number
        $segment = $this->parseSegment();
        if ($segment === null) {
            throw new ParseException(
                sprintf('Expected chapter number at position %d', $this->current()->position),
                $this->current()->position
            );
        }
        $segments[] = $segment;

        // Additional segments after EXPLICIT_VERSE_SEPARATOR
        while ($this->check(TokenType::EXPLICIT_VERSE_SEPARATOR)) {
            $dotPos = $this->current()->position;
            $this->advance(); // consume "."
            $verseOrRange = $this->parseVerseRef();
            if ($verseOrRange === null) {
                throw new ParseException(
                    sprintf('Expected verse number after verse separator at position %d', $dotPos),
                    $dotPos
                );
            }
            $segments[] = $verseOrRange;
        }

        return $segments;
    }

    private function parseSegment(): VerseRef|VerseRange|null
    {
        if (!$this->check(TokenType::CHAPTER_NUMBER)) {
            return null;
        }

        $chapterPos = $this->current()->position;
        $chapter    = (int) $this->current()->value;
        $this->advance();

        $alternateChapter = $this->parseAltChapter();

        $verse       = null;
        $verseSuffix = null;

        if ($this->check(TokenType::CHAPTER_VERSE_SEPARATOR)) {
            $this->advance(); // consume ","
            if (!$this->check(TokenType::VERSE_NUMBER)) {
                throw new ParseException(
                    sprintf('Expected verse number after chapter-verse separator at position %d', $this->current()->position),
                    $this->current()->position
                );
            }
            [$verse, $verseSuffix] = $this->parseVerse();
        }

        $fromRef = new VerseRef($this->book, $chapter, $alternateChapter, $verse, $verseSuffix, $chapterPos);

        // Check for range
        if ($this->check(TokenType::RANGE_SEPARATOR)) {
            $sepPos = $this->current()->position;
            $this->advance(); // consume "-"
            $namesChapter = $this->check(TokenType::CHAPTER_NUMBER);
            $toRef        = $this->parseRangeTarget($chapter, $alternateChapter);
            if ($toRef === null) {
                throw new ParseException(
                    sprintf('Expected chapter or verse number after range separator at position %d', $sepPos),
                    $sepPos
                );
            }
            if ($namesChapter && $toRef->verse !== null) {
                $toRef = $this->extendRangeEnd($toRef);
            }
            return new VerseRange($fromRef, $toRef);
        }

        return $fromRef;
    }

    /**
     * A range that ends on a chapter and verse may carry on in that chapter: "1,1-2,1-2" ends on 2,2, as it is
     * written when the end of a passage is quoted by its own verses (Jonah 1:1–2:1-2). The verse must come after
     * the end it extends. Nothing else follows a range's end with "-", so no other reference changes meaning.
     */
    private function extendRangeEnd(VerseRef $end): VerseRef
    {
        if (!$this->check(TokenType::RANGE_SEPARATOR)) {
            return $end;
        }
        $sepPos = $this->current()->position;
        $this->advance(); // consume "-"
        if (!$this->check(TokenType::VERSE_NUMBER)) {
            throw new ParseException(
                sprintf('Expected a verse number after range separator at position %d', $sepPos),
                $sepPos
            );
        }
        $versePos              = $this->current()->position;
        [$verse, $verseSuffix] = $this->parseVerse();
        if ($verse <= (int) $end->verse) {
            throw new ParseException(
                sprintf('The verse after the range separator at position %d must come after verse %d', $sepPos, (int) $end->verse),
                $sepPos
            );
        }
        return new VerseRef($this->book, $end->chapter, $end->alternateChapter, $verse, $verseSuffix, $versePos);
    }

    private function parseRangeTarget(int $fromChapter, ?int $fromAltChapter): ?VerseRef
    {
        // rangeTarget → CHAPTER_NUMBER altChapter? (CHAPTER_VERSE_SEPARATOR verse)? | verse
        if ($this->check(TokenType::CHAPTER_NUMBER)) {
            $chapterPos = $this->current()->position;
            $chapter    = (int) $this->current()->value;
            $this->advance();

            $alternateChapter = $this->parseAltChapter();

            $verse       = null;
            $verseSuffix = null;

            if ($this->check(TokenType::CHAPTER_VERSE_SEPARATOR)) {
                $this->advance();
                if (!$this->check(TokenType::VERSE_NUMBER)) {
                    throw new ParseException(
                        sprintf('Expected verse number after chapter-verse separator at position %d', $this->current()->position),
                        $this->current()->position
                    );
                }
                [$verse, $verseSuffix] = $this->parseVerse();
            }

            return new VerseRef($this->book, $chapter, $alternateChapter, $verse, $verseSuffix, $chapterPos);
        }

        if ($this->check(TokenType::VERSE_NUMBER)) {
            $versePos              = $this->current()->position;
            [$verse, $verseSuffix] = $this->parseVerse();
            // Same chapter as the "from" side
            return new VerseRef($this->book, $fromChapter, $fromAltChapter, $verse, $verseSuffix, $versePos);
        }

        return null;
    }

    /**
     * Parse a verse reference (after EXPLICIT_VERSE_SEPARATOR), which may itself be a range.
     */
    private function parseVerseRef(): VerseRef|VerseRange|null
    {
        if (!$this->check(TokenType::VERSE_NUMBER)) {
            return null;
        }

        // We need the current chapter context from the first segment.
        // The chapter is inherited from the last segment's chapter.
        [$chapter, $alternateChapter] = $this->inferCurrentChapter();
        $versePos                     = $this->current()->position;

        [$verse, $verseSuffix] = $this->parseVerse();
        $fromRef               = new VerseRef($this->book, $chapter, $alternateChapter, $verse, $verseSuffix, $versePos);

        if ($this->check(TokenType::RANGE_SEPARATOR)) {
            $sepPos = $this->current()->position;
            $this->advance();
            if (!$this->check(TokenType::VERSE_NUMBER)) {
                throw new ParseException(
                    sprintf('Expected verse number after range separator at position %d', $sepPos),
                    $sepPos
                );
            }
            $toPos                = $this->current()->position;
            [$toVerse, $toSuffix] = $this->parseVerse();
            $toRef                = new VerseRef($this->book, $chapter, $alternateChapter, $toVerse, $toSuffix, $toPos);
            return new VerseRange($fromRef, $toRef);
        }

        return $fromRef;
    }

    /**
     * Parse a VERSE_NUMBER optionally followed by PARTIAL_VERSE_SUFFIX.
     *
     * @return array{int, ?string}
     */
    private function parseVerse(): array
    {
        $verse = (int) $this->current()->value;
        $this->advance();

        $suffix = null;
        if ($this->check(TokenType::PARTIAL_VERSE_SUFFIX)) {
            $suffix = $this->current()->value;
            $this->advance();
        }

        return [$verse, $suffix];
    }

    private function parseAltChapter(): ?int
    {
        if (!$this->check(TokenType::OPEN_PARENTHESIS)) {
            return null;
        }
        $openPos = $this->current()->position;
        $this->advance(); // consume "("

        if (!$this->check(TokenType::ALTERNATE_CHAPTER)) {
            throw new ParseException(
                sprintf('Expected alternate chapter number after opening parenthesis at position %d', $openPos),
                $openPos
            );
        }
        $alt = (int) $this->current()->value;
        $this->advance();

        if (!$this->check(TokenType::CLOSE_PARENTHESIS)) {
            throw new ParseException(
                sprintf('Expected closing parenthesis after alternate chapter number at position %d', $this->current()->position),
                $this->current()->position
            );
        }
        $this->advance(); // consume ")"

        return $alt;
    }

    /**
     * Infer the current chapter from previously parsed tokens.
     * Walks backward through the token stream to find the last CHAPTER_NUMBER,
     * together with the alternate chapter written right after it, if any: in
     * "Psalm 88 (89), 2-3.4" verse 4 belongs to 88 (89), not to a bare 88 (#210).
     *
     * @return array{int, ?int}
     */
    private function inferCurrentChapter(): array
    {
        for ($i = $this->pos - 1; $i >= 0; $i--) {
            if ($this->tokens[$i]->type === TokenType::CHAPTER_NUMBER) {
                $alternate = null;
                if (
                    ( $this->tokens[$i + 1] ?? null )?->type === TokenType::OPEN_PARENTHESIS
                    && ( $this->tokens[$i + 2] ?? null )?->type === TokenType::ALTERNATE_CHAPTER
                ) {
                    $alternate = (int) $this->tokens[$i + 2]->value;
                }
                return [(int) $this->tokens[$i]->value, $alternate];
            }
        }
        return [1, null];
    }

    private function current(): Token
    {
        if ($this->pos >= count($this->tokens)) {
            throw new ParseException('Unexpected end of token stream', $this->pos);
        }
        return $this->tokens[$this->pos];
    }

    private function check(TokenType $type): bool
    {
        return $this->pos < count($this->tokens) && $this->tokens[$this->pos]->type === $type;
    }

    private function advance(): void
    {
        if ($this->pos < count($this->tokens)) {
            $this->pos++;
        }
    }
}
