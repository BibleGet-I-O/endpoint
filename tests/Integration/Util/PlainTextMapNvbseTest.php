<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Util;

use BibleGet\Api\Util\PlainTextMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checks PlainTextMap against every verse of the Nova Vulgata (NVBSE).
 *
 * The edition is copyrighted, so its rows are not in the repository: set
 * BIBLEGET_NVBSE_ROWS to a file of rows separated by U+001E, each `text`
 * U+001F `text_plain` as stored in the database. Without it the test is
 * skipped. Failures name only the row index, never its text.
 */
#[Group('slow')]
class PlainTextMapNvbseTest extends TestCase
{
    private const string ROW_SEPARATOR   = "\u{1E}";
    private const string FIELD_SEPARATOR = "\u{1F}";

    public function testEveryNvbseRowRoundTrips(): void
    {
        $file = getenv('BIBLEGET_NVBSE_ROWS');
        if ($file === false || $file === '' || !is_file($file)) {
            $this->markTestSkipped('BIBLEGET_NVBSE_ROWS does not name a file of NVBSE rows');
        }
        $contents = file_get_contents($file);
        $this->assertIsString($contents);
        $rows = explode(self::ROW_SEPARATOR, rtrim($contents, "\n"));

        $words = 0;
        foreach ($rows as $index => $row) {
            $fields = explode(self::FIELD_SEPARATOR, $row);
            $this->assertCount(2, $fields, "row $index has two fields");
            [$text, $textPlain] = $fields;
            $map                = PlainTextMap::of($text);
            $this->assertTrue($map->plain() === $textPlain, "row $index: plain() differs from text_plain");
            $words += $this->checkWords($map, $text, $index);
        }
        fwrite(STDERR, sprintf("\nNVBSE: %d rows, %d words round-trip\n", count($rows), $words));
    }

    private function checkWords(PlainTextMap $map, string $text, int $index): int
    {
        $plain = $map->plain();
        preg_match_all('/[^ ]+/u', $plain, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$word, $byte]) {
            $start = self::utf16Length(substr($plain, 0, $byte));
            $span  = $map->toText($start, $start + self::utf16Length($word));
            $this->assertNotNull($span, "row $index: a word's span is out of range");
            [$a, $b] = $span;
            $slice   = PlainTextMap::of(mb_substr($text, $a, $b - $a))->plain();
            $this->assertTrue($slice === $word, "row $index: a word's slice of text is not that word");
        }

        return count($matches[0]);
    }

    private static function utf16Length(string $s): int
    {
        return intdiv(strlen((string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }
}
