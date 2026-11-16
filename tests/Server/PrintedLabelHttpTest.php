<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * References select verses by the number the edition prints, including a
 * chapter:verse label printed in another chapter (fixture edition TEST3,
 * laid out like NABRE's Job 9-10 and Wis 11-12 with placeholder text).
 */
#[Group('slow')]
final class PrintedLabelHttpTest extends ServerTestCase
{
    /**
     * @return array<string, array{string, list<array{int, int, ?string}>}>
     */
    public static function references(): array
    {
        // Since migration 045 the moved rows are stored at their identity, and since 046
        // verse_segment holds only a printed letter (see testPlacementFields).
        $wis12 = [[12, 1, null], [12, 2, null], [12, 3, null], [12, 4, 'a'], [12, 5, null]];
        return [
            'Wis 12:1 printed after 11:26' => ['Wis12,1', [[12, 1, null]]],
            'Wis 12:1-5'                   => ['Wis12,1-5', $wis12],
            'Wis 12 whole chapter'         => ['Wis12', $wis12],
            'Wis 11 excludes 12:1'         => ['Wis11', [[11, 25, null], [11, 26, null]]],
            'Wis 11:26 alone'              => ['Wis11,26', [[11, 26, null]]],
            'Wis 11:25-12:2'               => ['Wis11,25-12,2', [[11, 25, null], [11, 26, null], [12, 1, null], [12, 2, null]]],
            'non-consecutive Wis 12:1.3'   => ['Wis12,1.3', [[12, 1, null], [12, 3, null]]],
            'Job 9:35 alone'               => ['Job9,35', [[9, 35, null]]],
            'Job 10:1 both parts'          => ['Job10,1', [[10, 1, null], [10, 1, null]]],
            'Job 9:35-10:2'                => ['Job9,35-10,2', [[9, 35, null], [10, 1, null], [10, 1, null], [10, 2, null]]],
        ];
    }

    /**
     * @param list<array{int, int, ?string}> $expected
     */
    #[DataProvider('references')]
    public function testReferenceSelectsByPrintedNumber(string $query, array $expected): void
    {
        $r = self::httpGet('/v3/quote?query=' . $query . '&version=TEST3');
        self::assertSame(200, $r['status']);
        $body = self::jsonBody($r['body']);
        self::assertSame([], $body['errors']);

        $rows = [];
        foreach ($body['results'] as $row) {
            $rows[] = [(int) $row['chapter'], (int) $row['verse'], $row['verse_segment'] ?? null];
        }
        self::assertSame($expected, $rows);
    }

    /**
     * @return array<string, array{string, list<array{int, int, ?int, ?string, bool}>}>
     */
    public static function placements(): array
    {
        return [
            'Wis 12:1 printed under 11, labelled 12:1'        => ['Wis12,1', [[12, 1, 11, '12:1', false]]],
            'Job 10:1: printed in 9 as 10:1, then unnumbered' => ['Job10,1', [[10, 1, 9, '10:1', false], [10, 1, null, '', false]]],
            'John 7:53 printed under 8'                       => ['Jn7,53', [[7, 53, 8, '7:53', false]]],
            'John 7 includes 7:53 (by number)'                => ['Jn7', [[7, 52, null, null, false], [7, 53, 8, '7:53', false]]],
            'John 8 does not'                                 => ['Jn8', [[8, 1, null, null, false], [8, 2, null, null, false]]],
            'an ordinary verse'                               => ['Wis11,26', [[11, 26, null, null, false]]],
            'omitted verses in a range'                       => ['Mk9,43-45', [[9, 43, null, null, false], [9, 44, null, null, true], [9, 45, null, null, false]]],
        ];
    }

    /**
     * Rows are stored at their identity; how the edition prints them is in
     * printed_chapter / verse_label, and omitted marks a number printed
     * without text (migrations 044/045).
     *
     * @param list<array{int, int, ?int, ?string, bool}> $expected
     */
    #[DataProvider('placements')]
    public function testPlacementFields(string $query, array $expected): void
    {
        $r = self::httpGet('/v3/quote?query=' . $query . '&version=TEST3');
        self::assertSame(200, $r['status']);
        $body = self::jsonBody($r['body']);
        self::assertSame([], $body['errors']);

        $rows = [];
        foreach ($body['results'] as $row) {
            self::assertArrayHasKey('printed_chapter', $row);
            self::assertArrayHasKey('verse_label', $row);
            self::assertArrayHasKey('omitted', $row);
            $rows[] = [(int) $row['chapter'], (int) $row['verse'], $row['printed_chapter'], $row['verse_label'], $row['omitted']];
            if ($row['omitted'] === true) {
                self::assertSame('', $row['text']);
            }
        }
        self::assertSame($expected, $rows);
    }

    /**
     * @return array<string, array{string, list<int>}>
     */
    public static function estherTexts(): array
    {
        return [
            'Hebrew text has 9:30'   => ['HEBREW', [29, 30, 31]],
            'Greek text has no 9:30' => ['GREEK', [29, 31]],
        ];
    }

    /**
     * @param list<int> $verses
     */
    #[DataProvider('estherTexts')]
    public function testEstherRangeStaysWithinOneText(string $origin, array $verses): void
    {
        $r = self::httpGet('/v3/quote?query=Est9,29-31&version=TEST3&preferorigin=' . $origin);
        self::assertSame(200, $r['status']);
        $body = self::jsonBody($r['body']);
        self::assertSame([], $body['errors']);
        $got = [];
        foreach ($body['results'] as $row) {
            self::assertSame($origin, $row['text_tradition']);
            $got[] = (int) $row['verse'];
        }
        self::assertSame($verses, $got);
    }

    public function testVersionIndexReportsNumberedBounds(): void
    {
        $r = self::httpGet('/v3/metadata/versionindex?versions=TEST3');
        self::assertSame(200, $r['status']);
        $idx = self::jsonBody($r['body'])['indexes']['TEST3'];
        $wis = array_search(27, $idx['book_num'], true);
        self::assertIsInt($wis);
        self::assertSame(26, $idx['verse_limit'][$wis][10]);
        self::assertSame(5, $idx['verse_limit'][$wis][11]);
    }

    public function testXmlCarriesPlacementFields(): void
    {
        $r = self::httpGet('/v3/quote?query=Wis12,1;Mk9,44&version=TEST3&return=xml');
        self::assertSame(200, $r['status']);
        $xml = simplexml_load_string($r['body']);
        self::assertNotFalse($xml);
        $rows = $xml->results->result;
        self::assertCount(2, $rows);
        self::assertSame('11', (string) $rows[0]['printed_chapter']);
        self::assertSame('12:1', (string) $rows[0]['verse_label']);
        self::assertSame('false', (string) $rows[0]['omitted']);
        self::assertSame('true', (string) $rows[1]['omitted']);
    }

    public function testHtmlMarksPlacementAndOmittedVerses(): void
    {
        $r = self::httpGet('/v3/quote?query=Wis12,1;Mk9,44&version=TEST3&return=html');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('data-printed-chapter="11"', $r['body']);
        self::assertStringContainsString('data-verse-label="12:1"', $r['body']);
        self::assertStringContainsString('data-omitted="true"', $r['body']);
        // The omitted verse: its number, and an empty text span.
        self::assertMatchesRegularExpression('/data-omitted="true"[^>]*>44<\/span><span class="text verseText"><\/span>/', $r['body']);
        // Ordinary verses carry none of the attributes.
        self::assertSame(1, substr_count($r['body'], 'data-printed-chapter='));
    }

    public function testHtmlPutsPrintedLabelUnderItsOwnChapter(): void
    {
        $r = self::httpGet('/v3/quote?query=Wis12,1-5&version=TEST3&return=html');
        self::assertSame(200, $r['status']);
        self::assertSame(1, substr_count($r['body'], 'class="book bookChapter"'));
    }
}
