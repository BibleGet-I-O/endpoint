<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #212 on staging, after migration 050: the Latin names of the Psalms that
 * liturgical books write resolve on NVBSE. The numbers are bare Hebrew ones here;
 * a dual-numbered "Psalmo 15 (16)" also needs #210.
 */
final class LatinPsalmsBookNamesLiveTest extends LiveTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function names(): array
    {
        return [
            'Psalmo (lectionary)'    => ['Psalmo'],
            'Psalmus (Nova Vulgata)' => ['Psalmus'],
            'Psalmi'                 => ['Psalmi'],
        ];
    }

    #[DataProvider('names')]
    public function testLatinNameResolvesOnNvbse(string $name): void
    {
        $r = self::liveGet('/quote?query=' . $name . '16,1-2a&version=NVBSE&return=json');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['errors']);
        self::assertIsArray($r['body']['results']);
        $got = [];
        foreach ($r['body']['results'] as $row) {
            self::assertIsArray($row);
            $got[] = [(int) $row['chapter'], (int) $row['verse']];
        }
        self::assertSame([[16, 1], [16, 2]], $got);
    }
}
