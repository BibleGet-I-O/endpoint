<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Tokenizer;

use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use BibleGet\Api\Pipeline\Tokenizer\Token;
use BibleGet\Api\Pipeline\Tokenizer\TokenType;
use PHPUnit\Framework\TestCase;

final class ReferenceTokenizerTest extends TestCase
{
    private ReferenceTokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new ReferenceTokenizer();
    }

    /**
     * Helper: extract [type, value] pairs (excluding EOF).
     *
     * @return array<int, array{TokenType, string}>
     */
    private function typeValues(string $input): array
    {
        $tokens = $this->tokenizer->tokenize($input);
        return array_values(array_map(
            fn(Token $t) => [$t->type, $t->value],
            array_filter($tokens, fn(Token $t) => $t->type !== TokenType::EOF)
        ));
    }

    // ── Basic book + chapter ──────────────────────────────────────

    public function testBookAndChapter(): void
    {
        $result = $this->typeValues('Genesis1');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '1'],
        ], $result);
    }

    public function testBookChapterVerse(): void
    {
        $result = $this->typeValues('Genesis1,5');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '1'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '5'],
        ], $result);
    }

    // ── Verse range (same chapter) ────────────────────────────────

    public function testVerseRange(): void
    {
        $result = $this->typeValues('Genesis1,5-10');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '1'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '5'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::VERSE_NUMBER, '10'],
        ], $result);
    }

    // ── Chapter range ─────────────────────────────────────────────

    public function testChapterRange(): void
    {
        $result = $this->typeValues('Genesis1-3');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '1'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::CHAPTER_NUMBER, '3'],
        ], $result);
    }

    // ── Cross-chapter verse range ─────────────────────────────────

    public function testCrossChapterRange(): void
    {
        $result = $this->typeValues('Genesis1,5-2,3');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '1'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '5'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::CHAPTER_NUMBER, '2'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '3'],
        ], $result);
    }

    // ── Non-consecutive verses ────────────────────────────────────

    public function testNonConsecutiveVerses(): void
    {
        $result = $this->typeValues('Genesis1,1-3.5.10');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '1'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '1'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::VERSE_NUMBER, '3'],
            [TokenType::EXPLICIT_VERSE_SEPARATOR, '.'],
            [TokenType::VERSE_NUMBER, '5'],
            [TokenType::EXPLICIT_VERSE_SEPARATOR, '.'],
            [TokenType::VERSE_NUMBER, '10'],
        ], $result);
    }

    // ── Numbered book ─────────────────────────────────────────────

    public function testNumberedBook(): void
    {
        $result = $this->typeValues('1John3,16');
        $this->assertSame([
            [TokenType::BOOK_NUMERIC_PREFIX, '1'],
            [TokenType::BOOK_NAME, 'John'],
            [TokenType::CHAPTER_NUMBER, '3'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '16'],
        ], $result);
    }

    // ── Partial verse suffix ──────────────────────────────────────

    public function testPartialVerseSuffix(): void
    {
        $result = $this->typeValues('Genesis2,4a');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '2'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '4'],
            [TokenType::PARTIAL_VERSE_SUFFIX, 'a'],
        ], $result);
    }

    public function testPartialVerseSuffixInRange(): void
    {
        $result = $this->typeValues('Genesis2,4a-7b');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '2'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '4'],
            [TokenType::PARTIAL_VERSE_SUFFIX, 'a'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::VERSE_NUMBER, '7'],
            [TokenType::PARTIAL_VERSE_SUFFIX, 'b'],
        ], $result);
    }

    // ── Dual Psalm numbering ──────────────────────────────────────

    public function testDualPsalmNumbering(): void
    {
        $result = $this->typeValues('Psalm51(50),1');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Psalm'],
            [TokenType::CHAPTER_NUMBER, '51'],
            [TokenType::OPEN_PARENTHESIS, '('],
            [TokenType::ALTERNATE_CHAPTER, '50'],
            [TokenType::CLOSE_PARENTHESIS, ')'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '1'],
        ], $result);
    }

    // ── EOF is always present ─────────────────────────────────────

    public function testEofAlwaysPresent(): void
    {
        $tokens = $this->tokenizer->tokenize('Genesis1');
        $last   = $tokens[count($tokens) - 1];
        $this->assertSame(TokenType::EOF, $last->type);
    }

    public function testEmptyInputProducesOnlyEof(): void
    {
        $tokens = $this->tokenizer->tokenize('');
        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::EOF, $tokens[0]->type);
    }

    // ── Position tracking ─────────────────────────────────────────

    public function testPositionsAreCorrect(): void
    {
        $tokens = $this->tokenizer->tokenize('Genesis1,5');
        // "Genesis" starts at 0, "1" at 7, "," at 8, "5" at 9
        $this->assertSame(0, $tokens[0]->position); // BOOK_NAME
        $this->assertSame(7, $tokens[1]->position); // CHAPTER_NUMBER
        $this->assertSame(8, $tokens[2]->position); // CHAPTER_VERSE_SEPARATOR
        $this->assertSame(9, $tokens[3]->position); // VERSE_NUMBER
    }

    // ── Unicode book names ────────────────────────────────────────

    public function testUnicodeBookName(): void
    {
        // Italian: "Gv" for Giovanni (John)
        $result = $this->typeValues('Gv3,16');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Gv'],
            [TokenType::CHAPTER_NUMBER, '3'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '16'],
        ], $result);
    }

    // ── Multi-digit chapters and verses ───────────────────────────

    public function testMultiDigitChapterAndVerse(): void
    {
        $result = $this->typeValues('Psalms119,176');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Psalms'],
            [TokenType::CHAPTER_NUMBER, '119'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '176'],
        ], $result);
    }

    // ── Whole chapter (no verse) ──────────────────────────────────

    public function testWholeChapter(): void
    {
        $result = $this->typeValues('Genesis1');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '1'],
        ], $result);
    }

    // ── Numbered book prefix 2, 3, 4 ─────────────────────────────

    public function testSecondBook(): void
    {
        $result = $this->typeValues('2Kings5,14');
        $this->assertSame([
            [TokenType::BOOK_NUMERIC_PREFIX, '2'],
            [TokenType::BOOK_NAME, 'Kings'],
            [TokenType::CHAPTER_NUMBER, '5'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '14'],
        ], $result);
    }

    // ── Reusability: tokenizer can be called multiple times ──────

    public function testTokenizerIsReusable(): void
    {
        $t1 = $this->typeValues('Genesis1');
        $t2 = $this->typeValues('John3,16');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Genesis'],
            [TokenType::CHAPTER_NUMBER, '1'],
        ], $t1);
        $this->assertSame([
            [TokenType::BOOK_NAME, 'John'],
            [TokenType::CHAPTER_NUMBER, '3'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '16'],
        ], $t2);
    }
}
