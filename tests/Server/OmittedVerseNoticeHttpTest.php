<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

/**
 * A reference that asks only for verses the edition omits (printed as a
 * number without text) gets the rows and a VERSE_OMITTED notice; a range that
 * merely includes them does not (fixture edition TEST3: Mk 9:44 and 9:46).
 */
#[Group('slow')]
final class OmittedVerseNoticeHttpTest extends ServerTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function quote(string $query, string $versions = 'TEST3', string $return = 'json'): array
    {
        $r = self::httpGet('/v3/quote?query=' . $query . '&version=' . $versions . '&return=' . $return);
        self::assertSame(200, $r['status']);
        return $r;
    }

    public function testOmittedVerseGetsRowAndNotice(): void
    {
        $body = self::jsonBody(self::quote('Mk9:44')['body']);
        self::assertSame([], $body['errors']);
        self::assertCount(1, $body['results']);
        self::assertTrue($body['results'][0]['omitted']);
        self::assertSame('', $body['results'][0]['text']);
        self::assertSame([
            [
                'code'       => 'VERSE_OMITTED',
                'version'    => 'TEST3',
                'book'       => 48,
                'chapter'    => 9,
                'verse_from' => 44,
                'verse_to'   => 44,
                'reference'  => 'Mk 9:44',
                'message'    => 'Mark 9:44 is not in the text of this edition: the critical text omits it; the number is printed without text.',
            ]
        ], $body['notices']);
    }

    public function testEachOmittedSegmentGetsItsNotice(): void
    {
        $body = self::jsonBody(self::quote('Mk9:44,46')['body']);
        self::assertSame(['Mk 9:44', 'Mk 9:46'], array_column($body['notices'], 'reference'));
        self::assertSame([46, 46], [$body['notices'][1]['verse_from'], $body['notices'][1]['verse_to']]);
    }

    public function testInheritedBookInReference(): void
    {
        // The second reference takes its book from the first.
        $body = self::jsonBody(self::quote('Mk9:43;9:44')['body']);
        self::assertSame(['Mk 9:44'], array_column($body['notices'], 'reference'));
    }

    public function testEuropeanNotationInReference(): void
    {
        $body = self::jsonBody(self::quote('Mk9,44')['body']);
        self::assertSame('Mk 9,44', $body['notices'][0]['reference']);
    }

    public function testRangeIncludingOmittedVersesHasNoNotice(): void
    {
        $body = self::jsonBody(self::quote('Mk9:43-45')['body']);
        self::assertCount(3, $body['results']);
        self::assertSame([], $body['notices']);
    }

    public function testWholeChapterHasNoNotice(): void
    {
        $body = self::jsonBody(self::quote('Mk9')['body']);
        self::assertSame([], $body['notices']);
    }

    public function testOnlyTheEditionThatOmitsGetsANotice(): void
    {
        $body = self::jsonBody(self::quote('Mk9:44', 'TEST3,TEST1')['body']);
        self::assertCount(2, $body['results']);
        self::assertSame(['TEST3'], array_column($body['notices'], 'version'));
    }

    public function testNoticesAlwaysPresent(): void
    {
        $body = self::jsonBody(self::quote('Wis12:1')['body']);
        self::assertSame([], $body['notices']);
    }

    public function testXmlCarriesNotices(): void
    {
        $xml = simplexml_load_string(self::quote('Mk9:44', 'TEST3', 'xml')['body']);
        self::assertNotFalse($xml);
        $notice = $xml->notices->notice[0];
        self::assertSame('VERSE_OMITTED', (string) $notice['code']);
        self::assertSame('Mk 9:44', (string) $notice['reference']);
        self::assertSame('44', (string) $notice['verse_from']);
        self::assertStringStartsWith('Mark 9:44 is not in the text', (string) $notice);
    }

    public function testHtmlCarriesNoticesHiddenOnly(): void
    {
        $html = self::quote('Mk9:44', 'TEST3', 'html')['body'];
        self::assertMatchesRegularExpression(
            '/<div class="info bibleQuote">.*<input type="hidden" name="notices" value="[^"]*VERSE_OMITTED[^"]*" class="BibleGetNotices">.*<\/div>/s',
            $html
        );
        // The message appears nowhere but in the hidden input's value.
        self::assertSame(1, substr_count($html, 'is not in the text of this edition'));
    }
}
