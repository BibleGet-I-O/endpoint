<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * References select verses by the number the edition prints, including a
 * chapter:verse label printed in another chapter (fixture edition TEST3,
 * laid out like NABRE's Job 9-10 and Wis 11-12 with placeholder text).
 */
#[Group('slow')]
final class PrintedLabelHttpTest extends ServerTestCase
{
    /**
     * @return array<string, array{string, list<array{int, int, ?string}>}>
     */
    public static function references(): array
    {
        $wis12 = [[12, 1, '12:1'], [12, 2, null], [12, 3, null], [12, 4, null], [12, 5, null]];
        return [
            'Wis 12:1 printed after 11:26' => ['Wis12,1', [[12, 1, '12:1']]],
            'Wis 12:1-5'                   => ['Wis12,1-5', $wis12],
            'Wis 12 whole chapter'         => ['Wis12', $wis12],
            'Wis 11 excludes 12:1'         => ['Wis11', [[11, 25, null], [11, 26, null]]],
            'Wis 11:26 alone'              => ['Wis11,26', [[11, 26, null]]],
            'Wis 11:25-12:2'               => ['Wis11,25-12,2', [[11, 25, null], [11, 26, null], [12, 1, '12:1'], [12, 2, null]]],
            'non-consecutive Wis 12:1.3'   => ['Wis12,1.3', [[12, 1, '12:1'], [12, 3, null]]],
            'Job 9:35 alone'               => ['Job9,35', [[9, 35, null]]],
            'Job 10:1 both parts'          => ['Job10,1', [[10, 1, '10:1a'], [10, 1, null]]],
            'Job 9:35-10:2'                => ['Job9,35-10,2', [[9, 35, null], [10, 1, '10:1a'], [10, 1, null], [10, 2, null]]],
        ];
    }

    /**
     * @param list<array{int, int, ?string}> $expected
     */
    #[DataProvider('references')]
    public function testReferenceSelectsByPrintedNumber(string $query, array $expected): void
    {
        $r = self::httpGet('/v3/quote?query=' . $query . '&version=TEST3');
        self::assertSame(200, $r['status']);
        $body = self::jsonBody($r['body']);
        self::assertSame([], $body['errors']);

        $rows = [];
        foreach ($body['results'] as $row) {
            $rows[] = [(int) $row['chapter'], (int) $row['verse'], $row['verseequiv'] ?? null];
        }
        self::assertSame($expected, $rows);
    }

    public function testVersionIndexReportsNumberedBounds(): void
    {
        $r = self::httpGet('/v3/metadata/versionindex?versions=TEST3');
        self::assertSame(200, $r['status']);
        $idx = self::jsonBody($r['body'])['indexes']['TEST3'];
        $wis = array_search(27, $idx['book_num'], true);
        self::assertIsInt($wis);
        self::assertSame(26, $idx['verse_limit'][$wis][10]);
        self::assertSame(5, $idx['verse_limit'][$wis][11]);
    }

    public function testHtmlPutsPrintedLabelUnderItsOwnChapter(): void
    {
        $r = self::httpGet('/v3/quote?query=Wis12,1-5&version=TEST3&return=html');
        self::assertSame(200, $r['status']);
        self::assertSame(1, substr_count($r['body'], 'class="book bookChapter"'));
    }
}
