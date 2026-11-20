<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Omitted verses (migration 045, #180): the edition prints the number without
 * text. A range returns them as rows with omitted = true and empty text; a
 * reference that asks only for them also gets a VERSE_OMITTED notice.
 */
final class OmittedVerseLiveTest extends LiveTestCase
{
    /**
     * NVBSE since migration 055: the Nova Vulgata prints an omitted verse's number in parentheses after the verse
     * before ("(44)", "(10. 11)", "(16 17)"); each is a row of its own and the marker is out of that verse's text.
     *
     * @return array<string, array{string, list<int>}>
     */
    public static function nvbseOmitted(): array
    {
        return [
            'Mc 9:43-48'   => ['Mc9,43-48', [44, 46]],
            'Sir 9:9-12'   => ['Sir9,9-12', [10, 11]],
            'Sir 29:15-18' => ['Sir29,15-18', [16, 17]],
        ];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('nvbseOmitted')]
    public function testNvbseOmittedVersesAreRowsAndTheMarkerIsGone(string $query, array $expected): void
    {
        $r = self::liveGet('/quote?query=' . $query . '&version=NVBSE&return=json');
        self::assertSame(200, $r['status']);
        $omitted = [];
        foreach ($r['body']['results'] as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['text']);
            if ($row['omitted'] === true) {
                self::assertSame('', $row['text']);
                $omitted[] = (int) $row['verse'];
            } else {
                self::assertDoesNotMatchRegularExpression('/\(\s*\d+(?:[.\s]+\d+)*\s*\)\s*$/', $row['text']);
            }
        }
        self::assertSame($expected, $omitted);
    }

    public function testNvbseOmittedVerseAloneGetsNotice(): void
    {
        $r = self::liveGet('/quote?query=Mt17,21&version=NVBSE&return=json');
        self::assertSame(200, $r['status']);
        self::assertCount(1, $r['body']['notices']);
        self::assertIsArray($r['body']['notices'][0]);
        self::assertSame('VERSE_OMITTED', $r['body']['notices'][0]['code']);
        self::assertSame('NVBSE', $r['body']['notices'][0]['version']);
    }

    public function testRangeReturnsOmittedRowsWithoutNotice(): void
    {
        $r = self::liveGet('/quote?query=Mc9,43-48&version=CEI2008&return=json');
        self::assertSame(200, $r['status']);
        $omitted = [];
        foreach ($r['body']['results'] as $row) {
            self::assertIsArray($row);
            if ($row['omitted'] === true) {
                self::assertSame('', $row['text']);
                $omitted[] = (int) $row['verse'];
            }
        }
        self::assertSame([44, 46], $omitted);
        self::assertSame([], $r['body']['notices']);
    }

    public function testOmittedVerseAloneGetsNotice(): void
    {
        $r = self::liveGet('/quote?query=Mc9,44&version=CEI2008&return=json');
        self::assertSame(200, $r['status']);
        self::assertCount(1, $r['body']['notices']);
        self::assertIsArray($r['body']['notices'][0]);
        self::assertSame('VERSE_OMITTED', $r['body']['notices'][0]['code']);
        self::assertSame('CEI2008', $r['body']['notices'][0]['version']);
        self::assertSame(44, $r['body']['notices'][0]['verse_from']);
    }

    public function testOmittedVerseIsNotSearchable(): void
    {
        $r = self::liveGet('/search/similar?reference=Mc9,44&version=CEI2008');
        self::assertSame(404, $r['status']);
    }
}
