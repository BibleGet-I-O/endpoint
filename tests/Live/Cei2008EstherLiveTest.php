<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CEI 2008 prints Esther twice, the Greek text and the Hebrew, told apart by
 * text_tradition. Migration 038 restored 8:2 (both texts) and Hebrew 9:30; the
 * Greek text has no 9:30. A range stays within the text asked for.
 */
final class Cei2008EstherLiveTest extends LiveTestCase
{
    /**
     * @return array<string, array{string, string, list<array{int, int}>}>
     */
    public static function ranges(): array
    {
        return [
            'Est 8:1-3 Hebrew'           => ['Est8:1-3', 'HEBREW', [[8, 1], [8, 2], [8, 3]]],
            'Est 8:1-3 Greek'            => ['Est8:1-3', 'GREEK', [[8, 1], [8, 2], [8, 3]]],
            'Est 9:29-31 Hebrew'         => ['Est9:29-31', 'HEBREW', [[9, 29], [9, 30], [9, 31]]],
            'Est 9:29-31 Greek, no 9:30' => ['Est9:29-31', 'GREEK', [[9, 29], [9, 31]]],
        ];
    }

    /**
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('ranges')]
    public function testRangeStaysWithinOneText(string $query, string $origin, array $expected): void
    {
        $r = self::liveGet('/quote?query=' . $query . '&version=CEI2008&return=json&preferorigin=' . $origin);
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['errors']);
        self::assertIsArray($r['body']['results']);
        $got = [];
        foreach ($r['body']['results'] as $row) {
            self::assertIsArray($row);
            self::assertSame($origin, $row['text_tradition']);
            $got[] = [(int) $row['chapter'], (int) $row['verse']];
        }
        self::assertSame($expected, $got);
    }
}
