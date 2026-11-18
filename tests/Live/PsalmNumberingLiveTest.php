<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #210: Psalm numbers are Hebrew by default, and of two numbers the larger
 * is the Hebrew one, whichever is in parentheses. Each edition returns the psalm
 * under its own numbering: Hebrew for CEI2008 and NVBSE, Vulgate for VGCL.
 */
final class PsalmNumberingLiveTest extends LiveTestCase
{
    /**
     * @return array<string, array{string, string, list<array{int, int}>}>
     */
    public static function citations(): array
    {
        return [
            // CEI prints the Vulgate number first; Hebrew 131 has only 3 verses.
            'CEI2008, Vulgate first'      => ['Sal131(132),6-7', 'CEI2008', [[132, 6], [132, 7]]],
            'CEI2008, Hebrew first'       => ['Sal132(131),6-7', 'CEI2008', [[132, 6], [132, 7]]],
            'NVBSE, Vulgate first'        => ['Ps15(16),11', 'NVBSE', [[16, 11]]],
            'NVBSE, bare Hebrew'          => ['Ps16,11', 'NVBSE', [[16, 11]]],
            'VGCL, Vulgate first'         => ['Ps88(89),2-3', 'VGCL', [[88, 2], [88, 3]]],
            'VGCL, bare Hebrew'           => ['Ps89,2', 'VGCL', [[88, 2]]],
            'VGCL, Hebrew 116:10 → 115:1' => ['Ps116,10', 'VGCL', [[115, 1]]],
            'VGCL, Hebrew 10:1 → 9:22'    => ['Ps10(9),1', 'VGCL', [[9, 22]]],
        ];
    }

    /**
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('citations')]
    public function testCitationReturnsTheEditionsOwnChapterAndVerse(string $query, string $version, array $expected): void
    {
        $r = self::liveGet('/quote?query=' . rawurlencode($query) . '&version=' . $version . '&return=json');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['errors']);
        self::assertIsArray($r['body']['results']);
        $got = [];
        foreach ($r['body']['results'] as $row) {
            self::assertIsArray($row);
            $got[] = [(int) $row['chapter'], (int) $row['verse']];
        }
        self::assertSame($expected, $got);
    }
}
