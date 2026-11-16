<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * DRB after migration 042, as on drbo.org: Prov 28:25 and Mal 3:12 had been
 * merged into the verse next to them, and Wis 18:25 was numbered 26.
 */
final class DrbLiveTest extends LiveTestCase
{
    /**
     * @return array<string, array{string, list<array{int, int, ?string}>}>
     */
    public static function verses(): array
    {
        return [
            'Prov 28:24-26' => ['Prov28:24-26', [[28, 24, null], [28, 25, null], [28, 26, null]]],
            'Wis 18:24-25'  => ['Wis18:24-25', [[18, 24, null], [18, 25, null]]],
            'Mal 3:11-12'   => ['Mal3:11-12', [[3, 11, null], [3, 12, null]]],
        ];
    }

    /**
     * @param list<array{int, int, ?string}> $expected
     */
    #[DataProvider('verses')]
    public function testVerse(string $query, array $expected): void
    {
        $r = self::liveGet('/quote?query=' . $query . '&version=DRB&return=json');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['errors']);
        self::assertSame($expected, self::labels($r['body']));
    }
}
