<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Tokenizer;

use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use BibleGet\Api\Pipeline\Tokenizer\Token;
use BibleGet\Api\Pipeline\Tokenizer\TokenType;
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
}
