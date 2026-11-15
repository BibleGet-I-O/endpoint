<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Util\PrintedLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrintedLabelTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, array{chapter: int, verse: int}}>
     */
    public static function rows(): array
    {
        return [
            'Wis 12:1 printed after 11:26' => [['chapter' => 11, 'verse' => 26, 'verseequiv' => '12:1'], ['chapter' => 12, 'verse' => 1]],
            'Job 10:1a printed after 9:35' => [['chapter' => 9, 'verse' => 35, 'verseequiv' => '10:1a'], ['chapter' => 10, 'verse' => 1]],
            'null verseequiv'              => [['chapter' => 3, 'verse' => 4, 'verseequiv' => null], ['chapter' => 3, 'verse' => 4]],
            'empty verseequiv'             => [['chapter' => 3, 'verse' => 4, 'verseequiv' => ''], ['chapter' => 3, 'verse' => 4]],
            'lettered segment'             => [['chapter' => 10, 'verse' => 18, 'verseequiv' => '18a'], ['chapter' => 10, 'verse' => 18]],
            'double letter'                => [['chapter' => 4, 'verse' => 17, 'verseequiv' => '17aa'], ['chapter' => 4, 'verse' => 17]],
            'psalm cross-number'           => [['chapter' => 10, 'verse' => 1, 'verseequiv' => '(9,22)'], ['chapter' => 10, 'verse' => 1]],
            'no verseequiv key'            => [['chapter' => 1, 'verse' => 1], ['chapter' => 1, 'verse' => 1]],
            'numeric strings'              => [['chapter' => '7', 'verse' => '53', 'verseequiv' => null], ['chapter' => 7, 'verse' => 53]],
            'trailing newline'             => [['chapter' => 11, 'verse' => 26, 'verseequiv' => "12:1\n"], ['chapter' => 11, 'verse' => 26]],
            'chapter of four digits'       => [['chapter' => 5, 'verse' => 6, 'verseequiv' => '1234:1'], ['chapter' => 5, 'verse' => 6]],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{chapter: int, verse: int} $expected
     */
    #[DataProvider('rows')]
    public function testCited(array $row, array $expected): void
    {
        self::assertSame($expected, PrintedLabel::cited($row));
    }

    public function testPatternBoundsTheNumbersSoTheIntCastCannotOverflow(): void
    {
        self::assertSame('^[0-9]{1,3}:[0-9]{1,3}[a-z]*$', PrintedLabel::PATTERN);
    }

    public function testSqlLabelledHandlesNull(): void
    {
        self::assertStringContainsString("coalesce(verseequiv, '')", PrintedLabel::sqlLabelled());
        self::assertStringContainsString(PrintedLabel::PATTERN, PrintedLabel::sqlLabelled());
    }

    public function testSqlExpressionsParseTheLabel(): void
    {
        $chapter = PrintedLabel::sqlChapter();
        $verse   = PrintedLabel::sqlVerse();
        self::assertStringContainsString('CASE WHEN ' . PrintedLabel::sqlLabelled(), $chapter);
        self::assertStringContainsString("split_part(verseequiv, ':', 1)", $chapter);
        self::assertStringContainsString('ELSE chapter END', $chapter);
        self::assertStringContainsString('CASE WHEN ' . PrintedLabel::sqlLabelled(), $verse);
        self::assertStringContainsString("substring(split_part(verseequiv, ':', 2) from '^[0-9]+')", $verse);
        self::assertStringContainsString('ELSE verse END', $verse);
    }
}
