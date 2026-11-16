<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

/**
 * Omitted verses (migration 045, #180): the edition prints the number without
 * text. A range returns them as rows with omitted = true and empty text; a
 * reference that asks only for them also gets a VERSE_OMITTED notice.
 */
final class OmittedVerseLiveTest extends LiveTestCase
{
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
