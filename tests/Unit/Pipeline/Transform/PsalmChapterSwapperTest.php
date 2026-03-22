<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Transform;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\Transform\PsalmChapterSwapper;
use PHPUnit\Framework\TestCase;

final class PsalmChapterSwapperTest extends TestCase
{
    private PsalmChapterSwapper $swapper;

    protected function setUp(): void
    {
        $this->swapper = new PsalmChapterSwapper();
    }

    // ── No swap for non-Psalm book ─────────────────────────────

    public function testNoSwapForNonPsalm(): void
    {
        $query  = new BibleQuery(1, [new VerseRef(1, 51, 50, 1)]);
        $result = $this->swapper->swap($query, 'VGCL');

        self::assertSame(51, $result->segments[0]->chapter);
        self::assertSame(50, $result->segments[0]->alternateChapter);
    }

    // ── No swap for non-Vulgate version ────────────────────────

    public function testNoSwapForNonVulgateVersion(): void
    {
        $query  = new BibleQuery(23, [new VerseRef(23, 51, 50, 1)]);
        $result = $this->swapper->swap($query, 'TEST1');

        self::assertSame(51, $result->segments[0]->chapter);
        self::assertSame(50, $result->segments[0]->alternateChapter);
    }

    // ── Swap for VGCL ──────────────────────────────────────────

    public function testSwapForVgcl(): void
    {
        $query  = new BibleQuery(23, [new VerseRef(23, 51, 50, 1)]);
        $result = $this->swapper->swap($query, 'VGCL');

        self::assertSame(50, $result->segments[0]->chapter);
        self::assertSame(51, $result->segments[0]->alternateChapter);
        self::assertSame(1, $result->segments[0]->verse);
    }

    // ── Swap for DRB ───────────────────────────────────────────

    public function testSwapForDrb(): void
    {
        $query  = new BibleQuery(23, [new VerseRef(23, 51, 50, 1)]);
        $result = $this->swapper->swap($query, 'DRB');

        self::assertSame(50, $result->segments[0]->chapter);
        self::assertSame(51, $result->segments[0]->alternateChapter);
    }

    // ── No swap when alternateChapter is null ───────────────────

    public function testNoSwapWithoutAlternate(): void
    {
        $query  = new BibleQuery(23, [new VerseRef(23, 51, null, 1)]);
        $result = $this->swapper->swap($query, 'VGCL');

        self::assertSame(51, $result->segments[0]->chapter);
        self::assertNull($result->segments[0]->alternateChapter);
    }

    // ── Swap in VerseRange ─────────────────────────────────────

    public function testSwapRange(): void
    {
        $from   = new VerseRef(23, 51, 50, 1);
        $to     = new VerseRef(23, 51, 50, 5);
        $query  = new BibleQuery(23, [new VerseRange($from, $to)]);
        $result = $this->swapper->swap($query, 'VGCL');

        $seg = $result->segments[0];
        self::assertInstanceOf(VerseRange::class, $seg);
        self::assertSame(50, $seg->from->chapter);
        self::assertSame(51, $seg->from->alternateChapter);
        self::assertSame(50, $seg->to->chapter);
        self::assertSame(51, $seg->to->alternateChapter);
    }

    // ── Mixed segments: only swap those with alternateChapter ──

    public function testMixedSegments(): void
    {
        $query  = new BibleQuery(23, [
            new VerseRef(23, 51, 50, 1),
            new VerseRef(23, 100, null, 1),
        ]);
        $result = $this->swapper->swap($query, 'VGCL');

        // First segment swapped
        self::assertSame(50, $result->segments[0]->chapter);
        // Second segment unchanged
        self::assertSame(100, $result->segments[1]->chapter);
        self::assertNull($result->segments[1]->alternateChapter);
    }

    // ── Preserves partial verse suffix ─────────────────────────

    public function testPreservesPartialSuffix(): void
    {
        $query  = new BibleQuery(23, [new VerseRef(23, 51, 50, 3, 'a')]);
        $result = $this->swapper->swap($query, 'VGCL');

        self::assertSame(50, $result->segments[0]->chapter);
        self::assertSame(3, $result->segments[0]->verse);
        self::assertSame('a', $result->segments[0]->partialSuffix);
    }
}
