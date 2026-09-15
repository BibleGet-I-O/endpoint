<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Util\StringUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StringUtilsTest extends TestCase
{
    // ── bibleBookVariants (#142) ───────────────────────────────────

    public function testVariantsKeepRawValuesFirst(): void
    {
        $variants = StringUtils::bibleBookVariants('Genesis', 'Gen | Gn');
        self::assertSame('Genesis', $variants[0]);
        self::assertSame('Gen | Gn', $variants[1]);
    }

    public function testVariantsNormalizeEveryFullName(): void
    {
        $variants = StringUtils::bibleBookVariants('1 Kings | 3 Kings', '1Kgs');
        self::assertContains('1Kings', $variants);
        self::assertContains('3Kings', $variants);
    }

    /**
     * A single abbreviation used to bypass normalization entirely, so a stored
     * "1 Kr" was only matchable by its raw, spaced form (#142).
     */
    public function testSingleAbbreviationWithWhitespaceIsNormalized(): void
    {
        $variants = StringUtils::bibleBookVariants('1 Kraljevima', '1 Kr');
        self::assertContains('1Kr', $variants);
    }

    public function testSingleAbbreviationWithInternalCapitalIsNormalized(): void
    {
        $variants = StringUtils::bibleBookVariants('Apostolok cselekedetei', 'ApCsel');
        self::assertContains('Apcsel', $variants);
    }

    public function testMultipleAbbreviationsAreAllNormalized(): void
    {
        $variants = StringUtils::bibleBookVariants('1 Chronicles', '1 Chron | 1 Chr');
        self::assertContains('1Chron', $variants);
        self::assertContains('1Chr', $variants);
    }

    public function testEmptyAbbreviationYieldsNoEmptyVariant(): void
    {
        $variants = StringUtils::bibleBookVariants('Genesis', '');
        self::assertSame(['Genesis', '', 'Genesis'], $variants);
    }

    // ── normalizeBibleBook ─────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'strips inner whitespace'  => ['1 Kings', '1Kings'],
            'trims'                    => ['  Gen ', 'Gen'],
            'proper-cases'             => ['GENESIS', 'Genesis'],
            'unicase script untouched' => ['創世紀', '創世紀'],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalizeBibleBook(string $input, string $expected): void
    {
        self::assertSame($expected, StringUtils::normalizeBibleBook($input));
    }
}
