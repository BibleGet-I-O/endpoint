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
        $ctx = new QuoteContext([], null, 'https://example.com', 'POST', '{"Accept":"*/*"}');
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

    /**
     * @return array<string, array{string, string}>
     */
    public static function unicodeDashProvider(): array
    {
        return [
            'U+2010 HYPHEN'                 => ["John3,16\u{2010}18", 'John3,16-18'],
            'U+2011 NON-BREAKING HYPHEN'    => ["John3,16\u{2011}18", 'John3,16-18'],
            'U+2012 FIGURE DASH'            => ["John3,16\u{2012}18", 'John3,16-18'],
            'U+2013 EN DASH'                => ["John3,16\u{2013}18", 'John3,16-18'],
            'U+2014 EM DASH'                => ["John3,16\u{2014}18", 'John3,16-18'],
            'U+2015 HORIZONTAL BAR'         => ["John3,16\u{2015}18", 'John3,16-18'],
            'U+2212 MINUS SIGN'             => ["John3,16\u{2212}18", 'John3,16-18'],
            'U+23AF HORIZONTAL LINE EXT'    => ["John3,16\u{23AF}18", 'John3,16-18'],
            'U+FE58 SMALL EM DASH'          => ["John3,16\u{FE58}18", 'John3,16-18'],
            'U+FE63 SMALL HYPHEN-MINUS'     => ["John3,16\u{FE63}18", 'John3,16-18'],
            'U+FF0D FULLWIDTH HYPHEN-MINUS' => ["John3,16\u{FF0D}18", 'John3,16-18'],
            'ASCII hyphen unchanged'        => ['John3,16-18', 'John3,16-18'],
        ];
    }

    #[DataProvider('unicodeDashProvider')]
    public function testQueryStrCleanNormalizesUnicodeDashes(string $input, string $expected): void
    {
        $ctx = new QuoteContext(['query' => $input]);
        $ctx->queryStrClean();
        self::assertSame($expected, $ctx->queries[0]);
    }

    /**
     * Verify Unicode dashes work with non-Latin book names.
     *
     * @return array<string, array{string, string}>
     */
    public static function unicodeDashWithNonLatinProvider(): array
    {
        return [
            'Chinese with en dash'             => ["創世記1,1\u{2013}3", '創世記1,1-3'],
            'Arabic with em dash'              => ["يوحنا3,16\u{2014}18", 'يوحنا3,16-18'],
            'Korean with fullwidth hyphen'     => ["창세기1,1\u{FF0D}3", '창세기1,1-3'],
            'Thai with figure dash'            => ["ปฐมกาล1,1\u{2012}3", 'ปฐมกาล1,1-3'],
            'Amharic with non-breaking hyphen' => ["ዘፍጥረት1,1\u{2011}3", 'ዘፍጥረት1,1-3'],
            'Japanese with minus sign'         => ["創世記1,1\u{2212}3", '創世記1,1-3'],
        ];
    }

    #[DataProvider('unicodeDashWithNonLatinProvider')]
    public function testUnicodeDashesWithNonLatinBookNames(string $input, string $expected): void
    {
        $ctx = new QuoteContext(['query' => $input]);
        $ctx->queryStrClean();
        self::assertSame($expected, $ctx->queries[0]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function referencePrefixProvider(): array
    {
        return [
            'Cf. with dot'     => ['Cf.John3,16', 'John3,16'],
            'cf. lowercase'    => ['cf.John3,16', 'John3,16'],
            'CF. uppercase'    => ['CF.John3,16', 'John3,16'],
            'Cfr. with dot'    => ['Cfr.John3,16', 'John3,16'],
            'cfr. lowercase'   => ['cfr.John3,16', 'John3,16'],
            'Cf without dot'   => ['CfJohn3,16', 'John3,16'],
            'Cfr without dot'  => ['CfrJohn3,16', 'John3,16'],
            'Confer'           => ['ConferJohn3,16', 'John3,16'],
            'confer lowercase' => ['conferJohn3,16', 'John3,16'],
            'no prefix'        => ['John3,16', 'John3,16'],
            'prefix on second' => ['Gen1,1;Cf.Ex2,3', 'Ex2,3'],
        ];
    }

    #[DataProvider('referencePrefixProvider')]
    public function testQueryStrCleanStripsReferencePrefix(string $input, string $expectedLastQuery): void
    {
        $ctx = new QuoteContext(['query' => $input]);
        $ctx->queryStrClean();
        $last = $ctx->queries[array_key_last($ctx->queries)];
        // Compare only the book+chapter+verse portion (notation may differ)
        self::assertStringStartsWith($expectedLastQuery, $last);
    }

    public function testErrorMessagesAreComplete(): void
    {
        for ($i = 0; $i <= 12; $i++) {
            self::assertArrayHasKey($i, QuoteContext::$errorMessages, "Error message $i should exist");
            self::assertNotEmpty(QuoteContext::$errorMessages[$i]);
        }
    }
}
