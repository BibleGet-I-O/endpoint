<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

/**
 * A quote the 30-verse copyright cap cuts short gets a VERSES_CAPPED notice, so a client can say so instead of
 * guessing (fixture: TEST2 is copyrighted; its synthetic Genesis 3 has 42 verses). A quote of 30 verses or fewer,
 * or of a public-domain edition, gets none.
 */
#[Group('slow')]
final class CappedQuoteNoticeHttpTest extends ServerTestCase
{
    /** @return array<string, mixed> */
    private static function quote(string $query, string $versions = 'TEST2', string $return = 'json'): array
    {
        $r = self::httpGet('/v3/quote?query=' . $query . '&version=' . $versions . '&return=' . $return);
        self::assertSame(200, $r['status']);
        return $r;
    }

    public function testACutQuoteSaysSo(): void
    {
        $body = self::jsonBody(self::quote('Genesis3')['body']);
        self::assertCount(30, $body['results']);
        $capped = array_values(array_filter($body['notices'], static fn (array $n): bool => $n['code'] === 'VERSES_CAPPED'));
        self::assertCount(1, $capped);
        self::assertSame('TEST2', $capped[0]['version']);
        self::assertSame(1, $capped[0]['book']);
        self::assertSame(3, $capped[0]['chapter']);
        self::assertSame(3, $capped[0]['chapter_to']);
        self::assertSame(1, $capped[0]['verse_from']);
        self::assertSame(30, $capped[0]['verse_to']);
        self::assertSame(30, $capped[0]['limit']);
        self::assertSame('Genesis3', $capped[0]['reference']);
        self::assertStringContainsString('only the first 30 verses', $capped[0]['message']);
    }

    /** Genesis 2:1 and 3:1-30 are 31 rows: the 30 given end at 3:29, in the next chapter. */
    public function testACutAcrossChaptersGivesTheEndingChapter(): void
    {
        $body   = self::jsonBody(self::quote('Genesis2,1-3,30')['body']);
        $capped = array_values(array_filter($body['notices'], static fn (array $n): bool => $n['code'] === 'VERSES_CAPPED'));
        self::assertCount(1, $capped);
        self::assertSame([2, 1, 3, 29], [$capped[0]['chapter'], $capped[0]['verse_from'], $capped[0]['chapter_to'], $capped[0]['verse_to']]);
        self::assertStringStartsWith('Genesis 2:1-3:29', $capped[0]['message']);
    }

    public function testThirtyOrFewerVersesGetNoNotice(): void
    {
        $body = self::jsonBody(self::quote('Genesis3,1-30')['body']);
        self::assertCount(30, $body['results']);
        self::assertSame([], $body['notices']);
    }

    public function testAPublicDomainEditionIsNeverCapped(): void
    {
        $body = self::jsonBody(self::quote('Genesis1', 'TEST1')['body']);
        self::assertSame([], array_filter($body['notices'], static fn (array $n): bool => $n['code'] === 'VERSES_CAPPED'));
    }

    public function testXmlCarriesTheNotice(): void
    {
        $xml = simplexml_load_string(self::quote('Genesis3', 'TEST2', 'xml')['body']);
        self::assertNotFalse($xml);
        self::assertCount(30, $xml->results->result);
        $notice = $xml->notices->notice[0];
        self::assertSame('VERSES_CAPPED', (string) $notice['code']);
        self::assertSame('30', (string) $notice['limit']);
        self::assertSame('3', (string) $notice['chapter_to']);
        self::assertStringContainsString('only the first 30 verses', (string) $notice);
    }

    public function testHtmlCarriesTheNoticeInTheHiddenInput(): void
    {
        $html = self::quote('Genesis3', 'TEST2', 'html')['body'];
        self::assertMatchesRegularExpression('/<input type="hidden" name="notices" value="[^"]*VERSES_CAPPED[^"]*" class="BibleGetNotices">/', $html);
    }
}
