<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * NVBSE after migration 041 (checked against vatican.va): verses the import had
 * merged into the verse before are rows of their own again, and the text spells
 * ae/oe out (the æ/œ ligatures, "Isræl" included, made "Israel" unfindable).
 */
final class NvbseLiveTest extends LiveTestCase
{
    /**
     * @return array<string, array{string, list<array{int, int, ?string}>}>
     */
    public static function restoredVerses(): array
    {
        return [
            'Num 3:9-10'   => ['Num3:9-10', [[3, 9, null], [3, 10, null]]],
            'Mt 17:20-22'  => ['Mt17:20-22', [[17, 20, null], [17, 22, null]]],
            'Acts 8:36-38' => ['Acts8:36-38', [[8, 36, null], [8, 38, null]]],
        ];
    }

    /**
     * @param list<array{int, int, ?string}> $expected
     */
    #[DataProvider('restoredVerses')]
    public function testRestoredVerse(string $query, array $expected): void
    {
        $r = self::liveGet('/quote?query=' . $query . '&version=NVBSE&return=json');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['errors']);
        self::assertSame($expected, self::labels($r['body']));
    }

    public function testKeywordSearchFindsIsrael(): void
    {
        $r = self::liveGet('/search/keyword?keyword=Israel&version=NVBSE');
        self::assertSame(200, $r['status']);
        self::assertIsArray($r['body']['results']);
        self::assertGreaterThan(1000, count($r['body']['results']));
    }
}
