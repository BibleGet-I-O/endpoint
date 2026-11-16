<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * NABRE prints Wis 12:1 after 11:26 and the first words of Job 10:1 after
 * 9:35. References select those verses by their number, on every endpoint, and
 * the index counts them in their own chapter (migration 035). Since migration
 * 045 the rows are stored at their identity, with where NABRE prints them in
 * printed_chapter / verse_label (the label checked below).
 * Text is checked only by md5 (NABRE is copyrighted).
 */
final class NabrePrintedLabelLiveTest extends LiveTestCase
{
    /** md5 of the text of the row printed as Wis 12:1 (stored after 11:26). */
    private const string WIS_12_1_MD5 = '84cc13c50de0013aaaa974a3c21ffb72';

    /** md5 of the text of the first part of Job 10:1 (stored after 9:35). */
    private const string JOB_10_1A_MD5 = 'c92a2696f8c8c3717482e39dc3c6ca7f';

    /**
     * @return array<string, array{string, list<array{int, int, ?string}>}>
     */
    public static function quotes(): array
    {
        return [
            'Wis 12:1'       => ['Wis12:1', [[12, 1, '12:1']]],
            'Wis 12:1-5'     => ['Wis12:1-5', [[12, 1, '12:1'], [12, 2, null], [12, 3, null], [12, 4, null], [12, 5, null]]],
            'Wis 11:26'      => ['Wis11:26', [[11, 26, null]]],
            'Wis 11:25-12:2' => ['Wis11:25-12:2', [[11, 25, null], [11, 26, null], [12, 1, '12:1'], [12, 2, null]]],
            'Job 9:35'       => ['Job9:35', [[9, 35, null]]],
            'Job 10:1'       => ['Job10:1', [[10, 1, '10:1'], [10, 1, '']]],
            'Job 9:35-10:2'  => ['Job9:35-10:2', [[9, 35, null], [10, 1, '10:1'], [10, 1, ''], [10, 2, null]]],
            'John 7:53'      => ['John7:53', [[7, 53, '7:53']]],
        ];
    }

    /**
     * @param list<array{int, int, ?string}> $expected
     */
    #[DataProvider('quotes')]
    public function testQuote(string $query, array $expected): void
    {
        $r = self::liveGet('/quote?query=' . $query . '&version=NABRE&return=json');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['errors']);
        self::assertSame($expected, self::printedLabels($r['body']));
    }

    public function testWholeChapters(): void
    {
        $wis12 = self::printedLabels(self::liveGet('/quote?query=Wis12&version=NABRE&return=json')['body']);
        self::assertCount(27, $wis12);
        self::assertSame([12, 1, '12:1'], $wis12[0]);

        $wis11 = self::printedLabels(self::liveGet('/quote?query=Wis11&version=NABRE&return=json')['body']);
        self::assertCount(26, $wis11);
        self::assertSame([11, 26, null], $wis11[25]);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function printedChapters(): array
    {
        return [
            'Wis 12:1 printed under 11'   => ['Wis12:1', 11, '12:1'],
            'Job 10:1 first part under 9' => ['Job10:1', 9, '10:1'],
            'John 7:53 printed under 8'   => ['John7:53', 8, '7:53'],
        ];
    }

    #[DataProvider('printedChapters')]
    public function testPrintedChapter(string $query, int $printedChapter, string $label): void
    {
        $r = self::liveGet('/quote?query=' . $query . '&version=NABRE&return=json');
        self::assertSame(200, $r['status']);
        self::assertIsArray($r['body']['results'][0]);
        self::assertSame($printedChapter, $r['body']['results'][0]['printed_chapter']);
        self::assertSame($label, $r['body']['results'][0]['verse_label']);
        self::assertFalse($r['body']['results'][0]['omitted']);
    }

    public function testTextHashes(): void
    {
        $wis = self::liveGet('/quote?query=Wis12:1&version=NABRE&return=json')['body'];
        self::assertIsArray($wis['results']);
        self::assertNotEmpty($wis['results']);
        self::assertIsArray($wis['results'][0]);
        self::assertSame(self::WIS_12_1_MD5, md5((string) $wis['results'][0]['text']));

        $job = self::liveGet('/quote?query=Job10:1&version=NABRE&return=json')['body'];
        self::assertIsArray($job['results']);
        self::assertNotEmpty($job['results']);
        self::assertIsArray($job['results'][0]);
        self::assertSame(self::JOB_10_1A_MD5, md5((string) $job['results'][0]['text']));
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function similarReferences(): array
    {
        return [
            'Wis 12:1' => ['Wis12:1', 12, 1],
            'Job 10:1' => ['Job10:1', 10, 1],
        ];
    }

    #[DataProvider('similarReferences')]
    public function testSimilarStartsFromPrintedNumberAndExcludesIt(string $reference, int $chapter, int $verse): void
    {
        $r = self::liveGet('/search/similar?reference=' . $reference . '&version=NABRE&model=labse');
        self::assertSame(200, $r['status']);
        $rows = self::printedLabels($r['body']);
        self::assertNotEmpty($rows);
        foreach ($rows as [$c, $v, $label]) {
            self::assertNotSame([$chapter, $verse], [$c, $v]);
            // Catch the source row by its printed label too.
            self::assertNotSame($reference === 'Job10:1' ? '10:1' : '12:1', $label);
        }
    }

    public function testKeywordHitReportsPrintedNumber(): void
    {
        $r = self::liveGet('/search/keyword?keyword=loathe&version=NABRE');
        self::assertSame(200, $r['status']);
        self::assertContains([10, 1, '10:1'], self::printedLabels($r['body']));
    }

    public function testVersionIndexCountsByNumber(): void
    {
        $idx = self::liveGet('/metadata/versionindex?versions=NABRE')['body']['indexes'];
        self::assertIsArray($idx);
        $nabre = $idx['NABRE'];
        self::assertIsArray($nabre);
        self::assertIsArray($nabre['book_num']);
        self::assertIsArray($nabre['verse_limit']);
        $wis  = array_search(27, $nabre['book_num'], true);
        $john = array_search(50, $nabre['book_num'], true);
        self::assertIsInt($wis);
        self::assertIsInt($john);
        self::assertSame(26, $nabre['verse_limit'][$wis][10]);
        self::assertSame(27, $nabre['verse_limit'][$wis][11]);
        self::assertSame(53, $nabre['verse_limit'][$john][6]);
    }
}
