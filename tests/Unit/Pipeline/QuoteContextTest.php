<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuoteContextTest extends TestCase
{
    public function testConstructorMergesDefaults(): void
    {
        $ctx = new QuoteContext(['query' => 'John3,16']);
        self::assertSame('John3,16', $ctx->DATA['query']);
        self::assertSame('', $ctx->DATA['version']);
        self::assertSame('', $ctx->DATA['return']);
    }

    public function testConstructorSetsOriginAndMethod(): void
    {
        $ctx = new QuoteContext([], 'https://example.com', 'POST', '{"Accept":"*/*"}');
        self::assertSame('https://example.com', $ctx->originHeader);
        self::assertSame('POST', $ctx->requestMethod);
        self::assertSame('{"Accept":"*/*"}', $ctx->jsonEncodedRequestHeaders);
    }

    public function testPreferOriginValidation(): void
    {
        $ctx = new QuoteContext(['preferorigin' => 'GREEK']);
        self::assertSame('GREEK', $ctx->DATA['preferorigin']);

        $ctx2 = new QuoteContext(['preferorigin' => 'INVALID']);
        self::assertSame('', $ctx2->DATA['preferorigin']);
    }

    public function testAddErrorMessage(): void
    {
        $ctx = new QuoteContext([]);
        $ctx->addErrorMessage(0);
        self::assertCount(1, $ctx->errors);
        self::assertSame(0, $ctx->errors[0]['errNum']);
        self::assertStringContainsString('valid book indicator', $ctx->errors[0]['errMessage']);
    }

    public function testAddErrorMessageWithString(): void
    {
        $ctx = new QuoteContext([]);
        $ctx->addErrorMessage('Custom error message');
        self::assertCount(1, $ctx->errors);
        self::assertSame(13, $ctx->errors[0]['errNum']);
        self::assertSame('Custom error message', $ctx->errors[0]['errMessage']);
    }

    public function testAddErrorMessageWithSuffix(): void
    {
        $ctx = new QuoteContext([]);
        $ctx->addErrorMessage(9, 'extra context');
        self::assertStringContainsString('> extra context', $ctx->errors[0]['errMessage']);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function upperLowerVariantProvider(): array
    {
        return [
            'Latin letters'  => ['John', true],
            'CJK characters' => ['創世記', false],
            'Arabic'         => ['يوحنا', false],
            'empty string'   => ['', false],
        ];
    }

    #[DataProvider('upperLowerVariantProvider')]
    public function testStringWithUpperAndLowerCaseVariants(string $str, bool $expected): void
    {
        self::assertSame($expected, QuoteContext::stringWithUpperAndLowerCaseVariants($str));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function properCaseProvider(): array
    {
        return [
            'lowercase'   => ['john', 'John'],
            'uppercase'   => ['JOHN', 'John'],
            'mixed'       => ['jOHN', 'John'],
            'already ok'  => ['John', 'John'],
            'with number' => ['1john', '1John'],
            'CJK'         => ['創世記', '創世記'],
        ];
    }

    #[DataProvider('properCaseProvider')]
    public function testToProperCase(string $input, string $expected): void
    {
        self::assertSame($expected, QuoteContext::toProperCase($input));
    }

    public function testIdxOfFindsNestedValue(): void
    {
        $haystack = [
            0 => [1 => ['Genesis', 'Gen', 'Gn']],
            1 => [1 => ['Exodus', 'Exod', 'Ex']],
        ];
        self::assertSame(0, QuoteContext::idxOf('Gen', $haystack));
        self::assertSame(1, QuoteContext::idxOf('Exodus', $haystack));
        self::assertFalse(QuoteContext::idxOf('NotABook', $haystack));
    }

    public function testQueryStrCleanEuropeanNotation(): void
    {
        $ctx = new QuoteContext(['query' => 'Giovanni3,16']);
        $ctx->queryStrClean();
        self::assertSame('EUROPEAN', $ctx->detectedNotation);
        self::assertCount(1, $ctx->queries);
        self::assertSame('Giovanni3,16', $ctx->queries[0]);
    }

    public function testQueryStrCleanEnglishNotation(): void
    {
        $ctx = new QuoteContext(['query' => 'John3:16']);
        $ctx->queryStrClean();
        self::assertSame('ENGLISH', $ctx->detectedNotation);
        // English notation gets converted to European internally
        self::assertCount(1, $ctx->queries);
        self::assertSame('John3,16', $ctx->queries[0]);
    }

    public function testQueryStrCleanMixedNotation(): void
    {
        $ctx = new QuoteContext(['query' => 'John3:16.18']);
        $ctx->queryStrClean();
        self::assertSame('MIXED', $ctx->detectedNotation);
    }

    public function testQueryStrCleanMultipleQueries(): void
    {
        $ctx = new QuoteContext(['query' => 'Gen1,1;Ex2,3']);
        $ctx->queryStrClean();
        self::assertCount(2, $ctx->queries);
    }

    public function testQueryStrCleanRemovesWhitespace(): void
    {
        $ctx = new QuoteContext(['query' => '  John  3 , 16  ']);
        $ctx->queryStrClean();
        self::assertSame('John3,16', $ctx->queries[0]);
    }

    public function testQueryStrCleanNormalizesUnicodeDashes(): void
    {
        // EN DASH (U+2013)
        $ctx = new QuoteContext(['query' => "John3,16\u{2013}18"]);
        $ctx->queryStrClean();
        self::assertStringContainsString('-', $ctx->queries[0]);
    }

    public function testErrorMessagesAreComplete(): void
    {
        for ($i = 0; $i <= 12; $i++) {
            self::assertArrayHasKey($i, QuoteContext::$errorMessages, "Error message $i should exist");
            self::assertNotEmpty(QuoteContext::$errorMessages[$i]);
        }
    }
}
