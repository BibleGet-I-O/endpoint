<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Transform;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\Transform\PsalmRemapper;
use PHPUnit\Framework\TestCase;

final class PsalmRemapperTest extends TestCase
{
    private PsalmRemapper $remapper;

    protected function setUp(): void
    {
        $this->remapper = new PsalmRemapper(['VGCL', 'DRB', 'CEI2008']);
    }

    private function assertVerseRef(BibleQuery $result, int $index, int $chapter, ?int $verse): void
    {
        $seg = $result->segments[$index];
        $this->assertInstanceOf(VerseRef::class, $seg);
        $this->assertSame($chapter, $seg->chapter);
        $this->assertSame($verse, $seg->verse);
    }

    // ── No remapping for non-VGCL/DRB versions ──────────────────

    public function testNoRemapForNonVgclDrb(): void
    {
        $query          = new BibleQuery(19, [new VerseRef(19, 10, null, 5)]);
        [$result, $pos] = $this->remapper->remap($query, 'CEI2008', '');

        $this->assertVerseRef($result, 0, 10, 5);
        $this->assertSame([''], $pos);
    }

    // ── No remapping for non-Psalms ──────────────────────────────

    public function testNoRemapForNonPsalms(): void
    {
        $query          = new BibleQuery(1, [new VerseRef(1, 10, null, 5)]);
        [$result, $pos] = $this->remapper->remap($query, 'VGCL', '');

        $this->assertVerseRef($result, 0, 10, 5);
    }

    // ── Mapping: Psalm 10:4-13 → chapter 10, verse 3 ────────────

    public function testRemapPsalm10Verse5InVgcl(): void
    {
        $query          = new BibleQuery(19, [new VerseRef(19, 10, null, 5)]);
        [$result, $pos] = $this->remapper->remap($query, 'VGCL', '');

        $this->assertVerseRef($result, 0, 10, 3);
        $this->assertStringContainsString('GREEK', $pos[0]);
    }

    // ── Mapping: Psalm 13:1-7 → chapter 3, verse 13 ─────────────

    public function testRemapPsalm13Verse3InDrb(): void
    {
        $query          = new BibleQuery(19, [new VerseRef(19, 13, null, 3)]);
        [$result, $pos] = $this->remapper->remap($query, 'DRB', '');

        $this->assertVerseRef($result, 0, 3, 13);
    }

    // ── Mapping: Psalm 16 (whole chapter, null verse) → 8, 12 ───

    public function testRemapPsalm16WholeChapter(): void
    {
        $query          = new BibleQuery(19, [new VerseRef(19, 16)]);
        [$result, $pos] = $this->remapper->remap($query, 'VGCL', '');

        $this->assertVerseRef($result, 0, 8, 12);
    }

    // ── No mapping for unmapped Psalm ────────────────────────────

    public function testNoRemapForUnmappedPsalm(): void
    {
        $query          = new BibleQuery(19, [new VerseRef(19, 50, null, 1)]);
        [$result, $pos] = $this->remapper->remap($query, 'VGCL', '');

        $this->assertVerseRef($result, 0, 50, 1);
    }

    // ── Range remapping ──────────────────────────────────────────

    public function testRemapRange(): void
    {
        $from           = new VerseRef(19, 10, null, 4);
        $to             = new VerseRef(19, 10, null, 10);
        $query          = new BibleQuery(19, [new VerseRange($from, $to)]);
        [$result, $pos] = $this->remapper->remap($query, 'VGCL', '');

        $seg = $result->segments[0];
        $this->assertInstanceOf(VerseRange::class, $seg);
        $this->assertSame(10, $seg->from->chapter);
        $this->assertSame(3, $seg->from->verse);
        $this->assertSame(10, $seg->to->chapter);
        $this->assertSame(3, $seg->to->verse);
    }

    // ── Default prefer origin passed through when no remapping ───

    public function testDefaultPreferOriginPassedThrough(): void
    {
        $query          = new BibleQuery(19, [new VerseRef(19, 50, null, 1)]);
        [$result, $pos] = $this->remapper->remap($query, 'VGCL', " AND verseorigin = 'HEBREW'");

        $this->assertSame([" AND verseorigin = 'HEBREW'"], $pos);
    }

    // ── Per-segment origins ──────────────────────────────────────

    public function testPerSegmentOrigins(): void
    {
        // Two segments: one mapped (Psalm 10:5), one unmapped (Psalm 50:1)
        $query          = new BibleQuery(19, [
            new VerseRef(19, 10, null, 5),
            new VerseRef(19, 50, null, 1),
        ]);
        [$result, $pos] = $this->remapper->remap($query, 'VGCL', '');

        $this->assertCount(2, $pos);
        $this->assertStringContainsString('GREEK', $pos[0]);
        $this->assertSame('', $pos[1]);
    }
}
