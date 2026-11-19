<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use BibleGet\Api\Database\Connection;
use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class QuoteHandlerHttpTest extends ServerTestCase
{
    // ── JSON responses ──────────────────────────────────────

    public function testSingleVerseJson(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/json', $r['headers']['content-type']);

        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('results', $body);
        self::assertArrayHasKey('errors', $body);
        self::assertArrayHasKey('info', $body);
        self::assertCount(1, $body['results']);
        self::assertStringContainsString('beginning', $body['results'][0]['text']);
    }

    public function testVerseRangeJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1-3&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertCount(3, $body['results']);
    }

    public function testWholeChapterJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertCount(10, $body['results']);
    }

    public function testMultipleVersionsJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1,TEST2');
        $body = self::jsonBody($r['body']);
        self::assertCount(2, $body['results']);
        $versions = array_column($body['results'], 'version');
        self::assertContains('TEST1', $versions);
        self::assertContains('TEST2', $versions);
    }

    public function testEnglishNotationJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1:1-3&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertCount(3, $body['results']);
        self::assertSame('ENGLISH', $body['info']['detectedNotation']);
    }

    public function testDiscontinuousVersesJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1.3.5&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertCount(3, $body['results']);
    }

    public function testInfoContainsEndpointVersion(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }

    public function testInfoContainsBibleVersionsInfo(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('bibleVersionsInfo', $body['info']);
        self::assertArrayHasKey('TEST1', $body['info']['bibleVersionsInfo']);
    }

    public function testResultStructure(): void
    {
        $r     = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1');
        $body  = self::jsonBody($r['body']);
        $verse = $body['results'][0];

        self::assertArrayHasKey('book', $verse);
        self::assertArrayHasKey('chapter', $verse);
        self::assertArrayHasKey('verse', $verse);
        self::assertArrayHasKey('text', $verse);
        self::assertArrayHasKey('version', $verse);
        self::assertArrayHasKey('bookabbrev', $verse);
        self::assertArrayHasKey('originalquery', $verse);
        self::assertArrayNotHasKey('sort_order', $verse);
    }

    /**
     * Since migration 046 the verse-identity fields have their new names, and
     * the old ones are gone from every format (a stale client fails visibly).
     */
    public function testResponsesCarryNoOldFieldNames(): void
    {
        $old = ['verseequiv', 'versedescr', 'verseorigin', 'verseID', 'sort_order'];
        // Esther 1:1-2 (text_tradition set) and Wis 12:4 (verse_segment 'a'): new
        // fields with values, so every format serializes at least one.
        foreach (['json', 'xml', 'html'] as $format) {
            foreach (['Est1,1-2', 'Wis12,4'] as $query) {
                $r = self::httpGet('/v3/quote?query=' . $query . '&version=TEST3&return=' . $format);
                self::assertSame(200, $r['status'], $format);
                foreach ($old as $name) {
                    self::assertStringNotContainsString($name, $r['body'], $format . ' carries ' . $name);
                }
            }
        }
        $wis = self::jsonBody(self::httpGet('/v3/quote?query=Wis12,4&version=TEST3')['body']);
        self::assertSame('a', $wis['results'][0]['verse_segment'] ?? null);

        $r    = self::httpGet('/v3/quote?query=Est1,1-2&version=TEST3');
        $body = self::jsonBody($r['body']);
        self::assertNotSame([], $body['results']);
        foreach ($body['results'] as $row) {
            foreach (['verse_segment', 'alt_ref', 'acrostic', 'text_tradition'] as $new) {
                self::assertArrayHasKey($new, $row);
            }
        }
    }

    // ── POST with JSON body ────────────────────────────────

    public function testPostJsonBody(): void
    {
        $r = self::httpPost(
            '/v3/quote',
            json_encode(['query' => 'Genesis1,1', 'version' => 'TEST1']) ?: '{}',
            ['Content-Type' => 'application/json']
        );
        self::assertSame(200, $r['status']);
        $body = self::jsonBody($r['body']);
        self::assertCount(1, $body['results']);
    }

    // ── XML response ───────────────────────────────────────

    public function testXmlResponse(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1&return=xml');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/xml', $r['headers']['content-type']);
        self::assertStringContainsString('<?xml', $r['body']);
        self::assertStringContainsString('BibleQuote', $r['body']);
    }

    // ── HTML response ──────────────────────────────────────

    public function testHtmlResponse(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1&return=html');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('text/html', $r['headers']['content-type']);
        self::assertStringContainsString('bibleQuote', $r['body']);
    }

    // ── Accept header negotiation ──────────────────────────

    public function testAcceptHeaderXml(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1', ['Accept' => 'application/xml']);
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/xml', $r['headers']['content-type']);
    }

    // ── Validation errors ──────────────────────────────────

    public function testMissingQueryParamReturnsError(): void
    {
        $r = self::httpGet('/v3/quote?version=TEST1');
        self::assertSame(422, $r['status']);
        self::assertStringContainsString('application/problem+json', $r['headers']['content-type']);
    }

    public function testMixedNotationReturnsError(): void
    {
        $r = self::httpGet('/v3/quote?query=John3:16.18&version=TEST1');
        self::assertStringContainsString('application/problem+json', $r['headers']['content-type']);
        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('Mixed notation', $body['detail']);
    }

    // ── Bot blocking ───────────────────────────────────────

    public function testBotUserAgentBlocked(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1', ['User-Agent' => 'Googlebot/2.1']);
        self::assertSame(403, $r['status']);
    }

    // ── Removed v3 parameters ──────────────────────────────

    private const PLACEHOLDER = 'force-parameter test placeholder';

    /** Psalms 1-5 in the TEST editions' index: 6+12+8+8+12 verses. */
    private const PSALMS_1_TO_5 = 46;

    private static function seedLongRangeAndUnlistedEdition(\PDO $db): void
    {
        $insert  = $db->prepare('INSERT INTO "TEST1" (book, chapter, verse, text, testament, section) VALUES (23, ?, ?, ?, 1, 7)');
        $insert2 = $db->prepare('INSERT INTO "TEST2" (book, chapter, verse, text, testament, section) VALUES (23, ?, ?, ?, 1, 7)');
        foreach ([1 => 6, 2 => 12, 3 => 8, 4 => 8, 5 => 12] as $chapter => $verses) {
            for ($verse = 1; $verse <= $verses; $verse++) {
                $insert->execute([$chapter, $verse, self::PLACEHOLDER]);
                $insert2->execute([$chapter, $verse, self::PLACEHOLDER]);
            }
        }
        // An edition with tables but no versions_available row: v3's forceversion served it.
        $db->exec('CREATE TABLE IF NOT EXISTS "TESTFV_idx" AS SELECT * FROM "TEST1_idx"');
        $db->exec('CREATE TABLE IF NOT EXISTS "TESTFV" AS SELECT * FROM "TEST1" WHERE book = 1');
    }

    private static function unseed(\PDO $db): void
    {
        $db->prepare('DELETE FROM "TEST1" WHERE text = ?')->execute([self::PLACEHOLDER]);
        $db->prepare('DELETE FROM "TEST2" WHERE text = ?')->execute([self::PLACEHOLDER]);
        $db->exec('DROP TABLE IF EXISTS "TESTFV_idx"');
        $db->exec('DROP TABLE IF EXISTS "TESTFV"');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function results(string $path): array
    {
        $r = self::httpGet($path);
        self::assertSame(200, $r['status'], $r['body']);
        /** @var list<array<string, mixed>> $results */
        $results = self::jsonBody($r['body'])['results'];
        return $results;
    }

    public function testForceParametersHaveNoEffect(): void
    {
        Connection::reset();
        try {
            $db = Connection::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Test database not available: ' . $e->getMessage());
        }
        self::unseed($db);
        try {
            // Inside the try: a seed that fails half-way is cleaned up too.
            self::seedLongRangeAndUnlistedEdition($db);
            // Copyrighted edition: limited to 30 with or without forcecopyright.
            self::assertCount(30, self::results('/v3/quote?query=Ps1-5&version=TEST2'));
            self::assertCount(30, self::results('/v3/quote?query=Ps1-5&version=TEST2&forcecopyright=true'));
            // Edition without copyright: whole, with or without forcecopyright.
            self::assertCount(self::PSALMS_1_TO_5, self::results('/v3/quote?query=Ps1-5&version=TEST1&forcecopyright=true'));
            // forceversion does not serve an edition missing from versions_available.
            $r    = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1,TESTFV&forceversion=true');
            $body = self::jsonBody($r['body']);
            self::assertSame(['TEST1'], array_values(array_unique(array_column($body['results'], 'version'))));
            self::assertStringContainsString('Not a valid version: <TESTFV>', (string) json_encode($body['errors']));
        } finally {
            self::unseed($db);
            Connection::reset();
        }
    }

    // ── numbering=edition and psalm_hebrew ─────────────────

    /**
     * @return array<string, mixed>
     */
    private static function body(string $path): array
    {
        $r = self::httpGet($path);
        self::assertSame(200, $r['status'], $r['body']);
        return self::jsonBody($r['body']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string> Each row's chapter:verse.
     */
    private static function refs(array $rows): array
    {
        return array_map(
            static fn (array $row): string => self::scalar($row['chapter']) . ':' . self::scalar($row['verse']),
            $rows
        );
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public function testEditionNumberingReadsVulgatePsalmsByTheirOwnNumber(): void
    {
        $edition = self::results('/v3/quote?query=Ps22&version=VGCL&numbering=edition');
        self::assertCount(6, $edition);
        self::assertSame([22], array_values(array_unique(array_column($edition, 'chapter'))));
        self::assertSame('23:1', $edition[0]['psalm_hebrew']);
        self::assertSame('VGCL Ps 22:1', $edition[0]['text']);

        $hebrew = self::results('/v3/quote?query=Ps22&version=VGCL');
        self::assertCount(32, $hebrew);
        self::assertSame([21], array_values(array_unique(array_column($hebrew, 'chapter'))));
        self::assertSame('22:1', $hebrew[0]['psalm_hebrew']);
    }

    public function testEditionNumberingIsTheDefaultForHebrewEditions(): void
    {
        $edition = self::results('/v3/quote?query=Ps23&version=TEST1&numbering=edition');
        $hebrew  = self::results('/v3/quote?query=Ps23&version=TEST1');

        self::assertCount(6, $edition);
        self::assertSame(self::refs($hebrew), self::refs($edition));
        self::assertSame(array_column($hebrew, 'text'), array_column($edition, 'text'));
        self::assertSame('23:4', $edition[3]['psalm_hebrew']);
    }

    public function testVulgatePsalmSpanningTwoHebrewPsalmsReportsBoth(): void
    {
        $rows = self::results('/v3/quote?query=Ps9&version=VGCL&numbering=edition');

        self::assertCount(39, $rows);
        self::assertSame('9:1', $rows[0]['psalm_hebrew']);
        self::assertSame('9:21', $rows[20]['psalm_hebrew']);
        self::assertSame('10:1', $rows[21]['psalm_hebrew']);
        self::assertSame('10:18', $rows[38]['psalm_hebrew']);
    }

    public function testEditionNumberingBoundsUseTheEditionIndex(): void
    {
        $edition = self::body('/v3/quote?query=Ps9,39&version=VGCL&numbering=edition');
        self::assertSame([], $edition['errors']);
        self::assertIsArray($edition['results']);
        self::assertCount(1, $edition['results']);
        self::assertSame('10:18', $edition['results'][0]['psalm_hebrew']);

        $hebrew = self::body('/v3/quote?query=Ps9,39&version=VGCL');
        self::assertSame([], $hebrew['results']);
        self::assertStringContainsString('Psalm <9> in the Hebrew numbering', (string) json_encode($hebrew['errors']));
    }

    public function testEditionNumberingKeepsTheThirtyVerseCap(): void
    {
        // TEST2 is copyrighted; its synthetic Genesis 3 has 35 verses.
        self::assertCount(30, self::results('/v3/quote?query=Genesis3&version=TEST2&numbering=edition'));
    }

    public function testUnknownNumberingFallsBackToHebrew(): void
    {
        $rows = self::results('/v3/quote?query=Ps22&version=VGCL&numbering=foo');

        self::assertSame(self::refs(self::results('/v3/quote?query=Ps22&version=VGCL')), self::refs($rows));
        self::assertSame([21], array_values(array_unique(array_column($rows, 'chapter'))));
    }

    public function testOtherBooksCarryNoPsalmHebrew(): void
    {
        $rows = self::results('/v3/quote?query=Genesis1,1&version=TEST1&numbering=edition');

        self::assertCount(1, $rows);
        self::assertArrayNotHasKey('psalm_hebrew', $rows[0]);
    }
}
