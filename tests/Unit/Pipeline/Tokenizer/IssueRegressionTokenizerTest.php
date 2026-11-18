<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Tokenizer;

use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use BibleGet\Api\Pipeline\Tokenizer\Token;
use BibleGet\Api\Pipeline\Tokenizer\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for tokenizer covering reported issues.
 */
final class IssueRegressionTokenizerTest extends TestCase
{
    private ReferenceTokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new ReferenceTokenizer();
    }

    /**
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

    /**
     * Issue #41: cross-chapter range + discontinuous verses.
     * Input after English→European normalization: Matthew9,35-10,1.5.6-8
     */
    public function testIssue41CrossChapterRangeWithDiscontinuousVerses(): void
    {
        $result = $this->typeValues('Matthew9,35-10,1.5.6-8');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Matthew'],
            [TokenType::CHAPTER_NUMBER, '9'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '35'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::CHAPTER_NUMBER, '10'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '1'],
            [TokenType::EXPLICIT_VERSE_SEPARATOR, '.'],
            [TokenType::VERSE_NUMBER, '5'],
            [TokenType::EXPLICIT_VERSE_SEPARATOR, '.'],
            [TokenType::VERSE_NUMBER, '6'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::VERSE_NUMBER, '8'],
        ], $result);
    }

    /**
     * Issue #42: verse letters (partial verse suffixes).
     */
    public function testIssue42PartialVerseSuffixes(): void
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

    public function testIssue42PartialVerseSuffixRange(): void
    {
        $result = $this->typeValues('Mark16,9b-20');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Mark'],
            [TokenType::CHAPTER_NUMBER, '16'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '9'],
            [TokenType::PARTIAL_VERSE_SUFFIX, 'b'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::VERSE_NUMBER, '20'],
        ], $result);
    }

    /**
     * Issue #46: dual Psalm numbering with parenthesized alternate chapter.
     */
    public function testIssue46DualPsalmNumbering(): void
    {
        $result = $this->typeValues('Psalm51(50),1-6');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Psalm'],
            [TokenType::CHAPTER_NUMBER, '51'],
            [TokenType::OPEN_PARENTHESIS, '('],
            [TokenType::ALTERNATE_CHAPTER, '50'],
            [TokenType::CLOSE_PARENTHESIS, ')'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '1'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::VERSE_NUMBER, '6'],
        ], $result);
    }

    /**
     * Issue #47: second query inherits book, after normalization becomes "9,1-3".
     */
    public function testIssue47BooklessQueryTokenizes(): void
    {
        $result = $this->typeValues('9,1-3');
        $this->assertSame([
            [TokenType::CHAPTER_NUMBER, '9'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '1'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::VERSE_NUMBER, '3'],
        ], $result);
    }

    /**
     * Issue #211: several consecutive parts of a verse ("11ab") are one suffix.
     */
    public function testIssue211MultiLetterVerseSuffix(): void
    {
        $result = $this->typeValues('Psalm16,10-11ab');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Psalm'],
            [TokenType::CHAPTER_NUMBER, '16'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '10'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::VERSE_NUMBER, '11'],
            [TokenType::PARTIAL_VERSE_SUFFIX, 'ab'],
        ], $result);
    }

    /**
     * Issue #211: a span of parts within one verse ("1b-e") is one suffix,
     * not a range to a book named "e".
     */
    public function testIssue211VerseSuffixSpan(): void
    {
        $result = $this->typeValues('Psalm110,1b-e.2.3');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Psalm'],
            [TokenType::CHAPTER_NUMBER, '110'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '1'],
            [TokenType::PARTIAL_VERSE_SUFFIX, 'b-e'],
            [TokenType::EXPLICIT_VERSE_SEPARATOR, '.'],
            [TokenType::VERSE_NUMBER, '2'],
            [TokenType::EXPLICIT_VERSE_SEPARATOR, '.'],
            [TokenType::VERSE_NUMBER, '3'],
        ], $result);
    }

    /**
     * Issue #211: a hyphen followed by a digit after a suffix is still a verse range.
     */
    public function testIssue211SuffixFollowedByVerseRange(): void
    {
        $result = $this->typeValues('Psalm51,3ab-4');
        $this->assertSame([
            [TokenType::BOOK_NAME, 'Psalm'],
            [TokenType::CHAPTER_NUMBER, '51'],
            [TokenType::CHAPTER_VERSE_SEPARATOR, ','],
            [TokenType::VERSE_NUMBER, '3'],
            [TokenType::PARTIAL_VERSE_SUFFIX, 'ab'],
            [TokenType::RANGE_SEPARATOR, '-'],
            [TokenType::VERSE_NUMBER, '4'],
        ], $result);
    }

    /**
     * Issue #211: letters that are not consecutive parts of a verse ("ex", "ba")
     * are not a suffix; they are left to the book-name reader, as before.
     *
     * @return array<string, array{string, string}>
     */
    public static function lettersThatAreNotASuffix(): array
    {
        return [
            'a word'               => ['Psalm51,3ex', 'ex'],
            'letters out of order' => ['Psalm51,3ba', 'ba'],
            // A decomposed accent (U+0301) makes "abc" the start of a word, as "abć" is.
            'a combining mark'     => ["Psalm51,3abc\u{0301}", "abc\u{0301}"],
        ];
    }

    #[DataProvider('lettersThatAreNotASuffix')]
    public function testIssue211LettersThatAreNotASuffixAreNotOne(string $input, string $letters): void
    {
        $result = $this->typeValues($input);
        $this->assertNotContains([TokenType::PARTIAL_VERSE_SUFFIX, $letters], $result);
        $this->assertContains([TokenType::BOOK_NAME, $letters], $result);
    }

    public function testIssue211SpanMustEndOnALaterPart(): void
    {
        $result = $this->typeValues('Psalm51,3a-a');
        $this->assertContains([TokenType::PARTIAL_VERSE_SUFFIX, 'a'], $result);
        $this->assertNotContains([TokenType::PARTIAL_VERSE_SUFFIX, 'a-a'], $result);
    }
}
