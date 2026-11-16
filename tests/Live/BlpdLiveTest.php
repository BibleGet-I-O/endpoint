<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BLPD after migration 039 (checked against vatican.va): the chapters missing
 * since the import are there, the Greek additions to Esther answer like in the
 * other Catholic editions, and Gen 47:5-6 follows the printed order. BLPD is
 * copyrighted, so a request returns at most 30 verses: long chapters are
 * checked by their last verses.
 */
final class BlpdLiveTest extends LiveTestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function chapters(): array
    {
        return [
            'Job 42'            => ['Job42', 17],
            'Isaiah 65'         => ['Is65', 25],
            'Isaiah 66'         => ['Is66', 24],
            'Jeremiah 51:60-64' => ['Jer51:60-64', 5],
            'Jeremiah 52:30-34' => ['Jer52:30-34', 5],
        ];
    }

    #[DataProvider('chapters')]
    public function testRestoredChapter(string $query, int $verses): void
    {
        $r = self::liveGet('/quote?query=' . $query . '&version=BLPD&return=json');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['errors']);
        self::assertCount($verses, self::labels($r['body']));
    }

    public function testGreekAdditionToEsther(): void
    {
        $r = self::liveGet('/quote?query=Est1:1&version=BLPD&return=json&preferorigin=GREEK');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['errors']);
        $labels = self::labels($r['body']);
        self::assertSame([1, 1, '1a'], $labels[0]);
        self::assertSame([1, 1, null], $labels[array_key_last($labels)]);
        self::assertCount(18, $labels);
    }

    public function testGenesis47PrintedOrder(): void
    {
        $r = self::liveGet('/quote?query=Gen47:5-6&version=BLPD&return=json');
        self::assertSame(200, $r['status']);
        self::assertSame([[47, 5, '5a'], [47, 6, '6b'], [47, 5, '5b']], self::labels($r['body']));
    }
}
