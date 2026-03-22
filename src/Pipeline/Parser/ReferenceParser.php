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
 *   segment     → chapterRef (RANGE_SEPARATOR rangeTarget)?
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

        // First segment starts with a chapter
        $segment = $this->parseSegment();
        if ($segment !== null) {
            $segments[] = $segment;
        }

        // Additional segments after EXPLICIT_VERSE_SEPARATOR
        while ($this->check(TokenType::EXPLICIT_VERSE_SEPARATOR)) {
            $this->advance(); // consume "."
            $verseOrRange = $this->parseVerseRef();
            if ($verseOrRange !== null) {
                $segments[] = $verseOrRange;
            }
        }

        return $segments;
    }

    private function parseSegment(): VerseRef|VerseRange|null
    {
        if (!$this->check(TokenType::CHAPTER_NUMBER)) {
            return null;
        }

        $chapter = (int) $this->current()->value;
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

        $fromRef = new VerseRef($this->book, $chapter, $alternateChapter, $verse, $verseSuffix);

        // Check for range
        if ($this->check(TokenType::RANGE_SEPARATOR)) {
            $sepPos = $this->current()->position;
            $this->advance(); // consume "-"
            $toRef = $this->parseRangeTarget($chapter, $alternateChapter, $verse);
            if ($toRef === null) {
                throw new ParseException(
                    sprintf('Expected chapter or verse number after range separator at position %d', $sepPos),
                    $sepPos
                );
            }
            return new VerseRange($fromRef, $toRef);
        }

        return $fromRef;
    }

    private function parseRangeTarget(int $fromChapter, ?int $fromAltChapter, ?int $fromVerse): ?VerseRef
    {
        // rangeTarget → CHAPTER_NUMBER altChapter? (CHAPTER_VERSE_SEPARATOR verse)? | verse
        if ($this->check(TokenType::CHAPTER_NUMBER)) {
            $chapter = (int) $this->current()->value;
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

            return new VerseRef($this->book, $chapter, $alternateChapter, $verse, $verseSuffix);
        }

        if ($this->check(TokenType::VERSE_NUMBER)) {
            [$verse, $verseSuffix] = $this->parseVerse();
            // Same chapter as the "from" side
            return new VerseRef($this->book, $fromChapter, $fromAltChapter, $verse, $verseSuffix);
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
        $chapter = $this->inferCurrentChapter();

        [$verse, $verseSuffix] = $this->parseVerse();
        $fromRef               = new VerseRef($this->book, $chapter, null, $verse, $verseSuffix);

        if ($this->check(TokenType::RANGE_SEPARATOR)) {
            $sepPos = $this->current()->position;
            $this->advance();
            if (!$this->check(TokenType::VERSE_NUMBER)) {
                throw new ParseException(
                    sprintf('Expected verse number after range separator at position %d', $sepPos),
                    $sepPos
                );
            }
            [$toVerse, $toSuffix] = $this->parseVerse();
            $toRef                = new VerseRef($this->book, $chapter, null, $toVerse, $toSuffix);
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
     * Walks backward through the token stream to find the last CHAPTER_NUMBER.
     */
    private function inferCurrentChapter(): int
    {
        for ($i = $this->pos - 1; $i >= 0; $i--) {
            if ($this->tokens[$i]->type === TokenType::CHAPTER_NUMBER) {
                return (int) $this->tokens[$i]->value;
            }
        }
        return 1;
    }

    private function current(): Token
    {
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
