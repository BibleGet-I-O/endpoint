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
            'Num 3:9-10'     => ['Num3:9-10', [[3, 9, null], [3, 10, null]]],
            // 055: the omitted verse between them (17:21, 8:37) is a row of its own.
            'Mt 17:20-22'    => ['Mt17:20-22', [[17, 20, null], [17, 21, null], [17, 22, null]]],
            'Acts 8:36-38'   => ['Acts8:36-38', [[8, 36, null], [8, 37, null], [8, 38, null]]],
            // 042: printed inline ("... moritur; 8 sive enim vivimus ...").
            'Rom 14:7-8'     => ['Rom14:7-8', [[14, 7, null], [14, 8, null]]],
            'Ezra 1:10-11'   => ['Ezra1:10-11', [[1, 10, null], [1, 11, null]]],
            // 042: stored as verse 0 (vatican.va prints "0 Hira").
            '1 Chr 11:39-41' => ['1Chr11:39-41', [[11, 39, null], [11, 40, null], [11, 41, null]]],
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
