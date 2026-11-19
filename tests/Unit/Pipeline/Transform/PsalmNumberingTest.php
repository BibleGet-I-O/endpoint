<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Transform;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\Transform\PsalmNumbering;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #210: Psalm numbers are Hebrew by default; of two numbers the larger is
 * the Hebrew one, whichever is in parentheses; Vulgate-numbered editions get the
 * Vulgate chapter and verse.
 */
final class PsalmNumberingTest extends TestCase
{
    private PsalmNumbering $numbering;

    protected function setUp(): void
    {
        $this->numbering = new PsalmNumbering();
    }

    private static function psalm(int $chapter, ?int $alternate = null, ?int $verse = null): BibleQuery
    {
        return new BibleQuery(23, [new VerseRef(23, $chapter, $alternate, $verse)]);
    }

    /**
     * @return array{int, ?int}
     */
    private static function chapterVerse(VerseRef $ref): array
    {
        return [$ref->chapter, $ref->verse];
    }

    public function testOtherBooksAreLeftAlone(): void
    {
        $query = new BibleQuery(1, [new VerseRef(1, 51, 50, 1)]);

        self::assertSame($query, $this->numbering->resolve($query, 'VGCL'));
    }

    /**
     * @return array<string, array{int, ?int, int}>
     */
    public static function hebrewReadings(): array
    {
        return [
            'bare number'                     => [51, null, 51],
            'Hebrew first, Vulgate in parens' => [51, 50, 51],
            'Vulgate first, Hebrew in parens' => [50, 51, 51],
            'equal numbers (147:12-20)'       => [147, 147, 147],
            'Hebrew 10 / Vulgate 9'           => [9, 10, 10],
            'Hebrew 116 / Vulgate 115'        => [115, 116, 116],
        ];
    }

    #[DataProvider('hebrewReadings')]
    public function testHebrewNumberedEditionsQueryTheHebrewNumber(int $chapter, ?int $alternate, int $hebrew): void
    {
        foreach (['CEI2008', 'NVBSE', 'NABRE'] as $version) {
            $ref = $this->numbering->resolve(self::psalm($chapter, $alternate, 3), $version)->segments[0];
            self::assertInstanceOf(VerseRef::class, $ref);
            self::assertSame([$hebrew, 3], self::chapterVerse($ref), $version);
            self::assertNull($ref->alternateChapter);
        }
    }

    /**
     * @return array<string, array{int, ?int, int, int, int}>
     */
    public static function vulgateReadings(): array
    {
        return [
            // chapter, alternate, Hebrew verse => Vulgate chapter, Vulgate verse
            'bare Hebrew number'          => [51, null, 3, 50, 3],
            'Hebrew first'                => [51, 50, 3, 50, 3],
            'Vulgate first'               => [50, 51, 3, 50, 3],
            'aligned psalm'               => [5, null, 2, 5, 2],
            'last aligned psalm'          => [150, null, 6, 150, 6],
            'Hebrew 9 stays in Vulgate 9' => [9, null, 21, 9, 21],
            'Hebrew 10 opens at Vg 9:22'  => [10, 9, 1, 9, 22],
            'Hebrew 10 ends at Vg 9:39'   => [10, null, 18, 9, 39],
            'Hebrew 114 is Vg 113:1-8'    => [114, 113, 8, 113, 8],
            'Hebrew 115 is Vg 113:9-26'   => [115, 113, 1, 113, 9],
            'Hebrew 116:9 is Vg 114:9'    => [116, 114, 9, 114, 9],
            'offset zone after 116'       => [118, 117, 22, 117, 22],
            'Hebrew 146 is Vg 145'        => [146, null, 1, 145, 1],
            'Hebrew 147:11 is Vg 146:11'  => [147, 146, 11, 146, 11],
        ];
    }

    #[DataProvider('vulgateReadings')]
    public function testVulgateNumberedEditionsQueryTheVulgateChapterAndVerse(
        int $chapter,
        ?int $alternate,
        int $verse,
        int $vulgateChapter,
        int $vulgateVerse
    ): void {
        foreach (PsalmNumbering::VULGATE_VERSIONS as $version) {
            $ref = $this->numbering->resolve(self::psalm($chapter, $alternate, $verse), $version)->segments[0];
            self::assertInstanceOf(VerseRef::class, $ref);
            self::assertSame([$vulgateChapter, $vulgateVerse], self::chapterVerse($ref), $version);
        }
    }

    /**
     * The second halves of Hebrew 116 and 147: the Clementine restarts the verse
     * numbers at 1, the Douay-Rheims keeps the Hebrew ones.
     *
     * @return array<string, array{int, ?int, int, string, int, int}>
     */
    public static function secondHalves(): array
    {
        return [
            'Hebrew 116:10, VGCL' => [115, 116, 10, 'VGCL', 115, 1],
            'Hebrew 116:19, VGCL' => [116, null, 19, 'VGCL', 115, 10],
            'Hebrew 147:12, VGCL' => [147, 147, 12, 'VGCL', 147, 1],
            'Hebrew 147:20, VGCL' => [147, null, 20, 'VGCL', 147, 9],
            'Hebrew 116:10, DRB'  => [115, 116, 10, 'DRB', 115, 10],
            'Hebrew 116:19, DRB'  => [116, null, 19, 'DRB', 115, 19],
            'Hebrew 147:12, DRB'  => [147, 147, 12, 'DRB', 147, 12],
            'Hebrew 147:20, DRB'  => [147, null, 20, 'DRB', 147, 20],
        ];
    }

    #[DataProvider('secondHalves')]
    public function testSecondHalvesFollowEachEditionsVerseNumbers(
        int $chapter,
        ?int $alternate,
        int $verse,
        string $version,
        int $vulgateChapter,
        int $vulgateVerse
    ): void {
        $ref = $this->numbering->resolve(self::psalm($chapter, $alternate, $verse), $version)->segments[0];

        self::assertInstanceOf(VerseRef::class, $ref);
        self::assertSame([$vulgateChapter, $vulgateVerse], self::chapterVerse($ref));
    }

    public function testAWholeSecondHalfOnDrbKeepsTheHebrewVerses(): void
    {
        $segment = $this->numbering->resolve(self::psalm(116), 'DRB')->segments[0];

        self::assertInstanceOf(VerseRange::class, $segment);
        self::assertSame([114, 1], self::chapterVerse($segment->from));
        self::assertSame([115, 19], self::chapterVerse($segment->to));
    }

    /**
     * @return array<string, array{int, array{int, int}, array{int, int}}>
     */
    public static function wholeSplitPsalms(): array
    {
        return [
            'Hebrew 9'   => [9, [9, 1], [9, 21]],
            'Hebrew 10'  => [10, [9, 22], [9, 39]],
            'Hebrew 114' => [114, [113, 1], [113, 8]],
            'Hebrew 115' => [115, [113, 9], [113, 26]],
            'Hebrew 116' => [116, [114, 1], [115, 10]],
            'Hebrew 147' => [147, [146, 1], [147, 9]],
        ];
    }

    /**
     * @param array{int, int} $from
     * @param array{int, int} $to
     */
    #[DataProvider('wholeSplitPsalms')]
    public function testAWholeSplitPsalmBecomesTheVulgateSpanItOccupies(int $hebrew, array $from, array $to): void
    {
        $segment = $this->numbering->resolve(self::psalm($hebrew), 'VGCL')->segments[0];

        self::assertInstanceOf(VerseRange::class, $segment);
        self::assertSame($from, self::chapterVerse($segment->from));
        self::assertSame($to, self::chapterVerse($segment->to));
    }

    public function testAWholePsalmOutsideTheSplitZonesStaysAWholeChapter(): void
    {
        $ref = $this->numbering->resolve(self::psalm(23), 'VGCL')->segments[0];

        self::assertInstanceOf(VerseRef::class, $ref);
        self::assertSame([22, null], self::chapterVerse($ref));
    }

    public function testARangeAcrossASplitMapsEachEnd(): void
    {
        // Hebrew 116:8-12 → VGCL 114:8 – 115:3
        $query   = new BibleQuery(23, [new VerseRange(new VerseRef(23, 116, 115, 8), new VerseRef(23, 116, 115, 12))]);
        $segment = $this->numbering->resolve($query, 'VGCL')->segments[0];

        self::assertInstanceOf(VerseRange::class, $segment);
        self::assertSame([114, 8], self::chapterVerse($segment->from));
        self::assertSame([115, 3], self::chapterVerse($segment->to));
    }

    public function testEverySegmentIsResolved(): void
    {
        // "Salmo 88 (89), 2-3.4" as the parser builds it: every segment carries 88 (89).
        $query = new BibleQuery(23, [
            new VerseRange(new VerseRef(23, 88, 89, 2), new VerseRef(23, 88, 89, 3)),
            new VerseRef(23, 88, 89, 4),
        ]);

        $hebrew = $this->numbering->resolve($query, 'CEI2008');
        self::assertInstanceOf(VerseRange::class, $hebrew->segments[0]);
        self::assertSame([89, 2], self::chapterVerse($hebrew->segments[0]->from));
        self::assertInstanceOf(VerseRef::class, $hebrew->segments[1]);
        self::assertSame([89, 4], self::chapterVerse($hebrew->segments[1]));

        $vulgate = $this->numbering->resolve($query, 'VGCL');
        self::assertInstanceOf(VerseRef::class, $vulgate->segments[1]);
        self::assertSame([88, 4], self::chapterVerse($vulgate->segments[1]));
    }

    public function testSuffixAndPositionArePreserved(): void
    {
        $query = new BibleQuery(23, [new VerseRef(23, 10, 9, 1, 'b', 7)]);
        $ref   = $this->numbering->resolve($query, 'VGCL')->segments[0];

        self::assertInstanceOf(VerseRef::class, $ref);
        self::assertSame('b', $ref->partialSuffix);
        self::assertSame(7, $ref->position);
    }

    public function testHebrewChapterIsTheLargerNumber(): void
    {
        self::assertSame(89, PsalmNumbering::hebrewChapter(new VerseRef(23, 88, 89)));
        self::assertSame(89, PsalmNumbering::hebrewChapter(new VerseRef(23, 89, 88)));
        self::assertSame(88, PsalmNumbering::hebrewChapter(new VerseRef(23, 88)));
    }

    public function testToHebrewIsIdentityForHebrewEditions(): void
    {
        self::assertSame([23, 4], PsalmNumbering::toHebrew(23, 4, 'NABRE'));
        self::assertSame([9, 22], PsalmNumbering::toHebrew(9, 22, 'TEST1'));
    }

    /**
     * @return array<string, array{string, int, int, int, int}>
     */
    public static function vulgateToHebrew(): array
    {
        return [
            'VGCL 9:1'    => ['VGCL', 9, 1, 9, 1],
            'VGCL 9:22'   => ['VGCL', 9, 22, 10, 1],
            'VGCL 9:39'   => ['VGCL', 9, 39, 10, 18],
            'VGCL 22:4'   => ['VGCL', 22, 4, 23, 4],
            'VGCL 113:9'  => ['VGCL', 113, 9, 115, 1],
            'VGCL 114:9'  => ['VGCL', 114, 9, 116, 9],
            'VGCL 115:1'  => ['VGCL', 115, 1, 116, 10],
            'VGCL 146:11' => ['VGCL', 146, 11, 147, 11],
            'VGCL 147:1'  => ['VGCL', 147, 1, 147, 12],
            'VGCL 150:6'  => ['VGCL', 150, 6, 150, 6],
            'DRB 115:10'  => ['DRB', 115, 10, 116, 10],
            'DRB 147:12'  => ['DRB', 147, 12, 147, 12],
        ];
    }

    #[DataProvider('vulgateToHebrew')]
    public function testToHebrewInvertsTheVulgateNumbering(string $version, int $chapter, int $verse, int $hebrewChapter, int $hebrewVerse): void
    {
        self::assertSame([$hebrewChapter, $hebrewVerse], PsalmNumbering::toHebrew($chapter, $verse, $version));
    }

    /**
     * Every Hebrew verse of a psalm the Vulgate splits or joins (all verses up to
     * hebrewVerseLimit), and verse 1 of every other psalm (outside the split zones the
     * verse number is kept as it is, so one verse per psalm checks its chapter).
     */
    public function testToHebrewRoundTripsResolve(): void
    {
        foreach (['VGCL', 'DRB'] as $version) {
            for ($chapter = 1; $chapter <= 150; $chapter++) {
                $last = PsalmNumbering::hebrewVerseLimit($chapter) ?? 1;
                for ($verse = 1; $verse <= $last; $verse++) {
                    $ref = $this->numbering->resolve(self::psalm($chapter, null, $verse), $version)->segments[0];
                    self::assertInstanceOf(VerseRef::class, $ref);
                    self::assertNotNull($ref->verse);
                    self::assertSame(
                        [$chapter, $verse],
                        PsalmNumbering::toHebrew($ref->chapter, $ref->verse, $version),
                        "$version Hebrew $chapter:$verse"
                    );
                }
            }
        }
    }
}
