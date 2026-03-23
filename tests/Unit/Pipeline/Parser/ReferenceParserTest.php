<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Parser;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\Parser\ReferenceParser;
use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use PHPUnit\Framework\TestCase;

final class ReferenceParserTest extends TestCase
{
    private ReferenceTokenizer $tokenizer;
    private ReferenceParser $parser;

    protected function setUp(): void
    {
        $this->tokenizer = new ReferenceTokenizer();
        $this->parser    = new ReferenceParser();
    }

    private function parse(string $input): BibleQuery
    {
        return $this->parser->parse($this->tokenizer->tokenize($input));
    }

    // ── Whole chapter ─────────────────────────────────────────────

    public function testWholeChapter(): void
    {
        $q = $this->parse('Genesis1');
        $this->assertCount(1, $q->segments);
        $this->assertInstanceOf(VerseRef::class, $q->segments[0]);
        $this->assertSame(1, $q->segments[0]->chapter);
        $this->assertNull($q->segments[0]->verse);
    }

    // ── Single verse ──────────────────────────────────────────────

    public function testSingleVerse(): void
    {
        $q = $this->parse('Genesis1,5');
        $this->assertCount(1, $q->segments);
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRef::class, $seg);
        $this->assertSame(1, $seg->chapter);
        $this->assertSame(5, $seg->verse);
    }

    // ── Verse range (same chapter) ────────────────────────────────

    public function testVerseRangeSameChapter(): void
    {
        $q = $this->parse('Genesis1,5-10');
        $this->assertCount(1, $q->segments);
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRange::class, $seg);
        $this->assertSame(1, $seg->from->chapter);
        $this->assertSame(5, $seg->from->verse);
        $this->assertSame(1, $seg->to->chapter);
        $this->assertSame(10, $seg->to->verse);
    }

    // ── Chapter range ─────────────────────────────────────────────

    public function testChapterRange(): void
    {
        $q = $this->parse('Genesis1-3');
        $this->assertCount(1, $q->segments);
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRange::class, $seg);
        $this->assertSame(1, $seg->from->chapter);
        $this->assertNull($seg->from->verse);
        $this->assertSame(3, $seg->to->chapter);
        $this->assertNull($seg->to->verse);
    }

    // ── Cross-chapter range ───────────────────────────────────────

    public function testCrossChapterRange(): void
    {
        $q = $this->parse('Genesis1,5-2,3');
        $this->assertCount(1, $q->segments);
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRange::class, $seg);
        $this->assertSame(1, $seg->from->chapter);
        $this->assertSame(5, $seg->from->verse);
        $this->assertSame(2, $seg->to->chapter);
        $this->assertSame(3, $seg->to->verse);
    }

    // ── Non-consecutive verses ────────────────────────────────────

    public function testNonConsecutiveVerses(): void
    {
        $q = $this->parse('Genesis1,1-3.5.10');
        $this->assertCount(3, $q->segments);

        // First: range 1:1-3
        $this->assertInstanceOf(VerseRange::class, $q->segments[0]);
        $this->assertSame(1, $q->segments[0]->from->verse);
        $this->assertSame(3, $q->segments[0]->to->verse);

        // Second: verse 1:5
        $this->assertInstanceOf(VerseRef::class, $q->segments[1]);
        $this->assertSame(1, $q->segments[1]->chapter);
        $this->assertSame(5, $q->segments[1]->verse);

        // Third: verse 1:10
        $this->assertInstanceOf(VerseRef::class, $q->segments[2]);
        $this->assertSame(1, $q->segments[2]->chapter);
        $this->assertSame(10, $q->segments[2]->verse);
    }

    // ── Partial verse suffix ──────────────────────────────────────

    public function testPartialVerseSuffix(): void
    {
        $q   = $this->parse('Genesis2,4a');
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRef::class, $seg);
        $this->assertSame(4, $seg->verse);
        $this->assertSame('a', $seg->partialSuffix);
    }

    public function testPartialVerseSuffixInRange(): void
    {
        $q   = $this->parse('Genesis2,4a-7b');
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRange::class, $seg);
        $this->assertSame(4, $seg->from->verse);
        $this->assertSame('a', $seg->from->partialSuffix);
        $this->assertSame(7, $seg->to->verse);
        $this->assertSame('b', $seg->to->partialSuffix);
    }

    // ── Dual Psalm numbering ──────────────────────────────────────

    public function testDualPsalmNumbering(): void
    {
        $q   = $this->parse('Psalm51(50),1');
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRef::class, $seg);
        $this->assertSame(51, $seg->chapter);
        $this->assertSame(50, $seg->alternateChapter);
        $this->assertSame(1, $seg->verse);
    }

    // ── Parse exception on invalid input ──────────────────────────

    public function testNoBookProducesZeroBookIndex(): void
    {
        // "123" has no book name — parser produces book=0 for caller to handle
        $q = $this->parse('123');
        $this->assertSame(0, $q->book);
    }

    // ── Numbered book ─────────────────────────────────────────────

    public function testNumberedBook(): void
    {
        $q = $this->parse('1John3,16');
        $this->assertSame(0, $q->book); // book=0 placeholder until validator resolves it
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRef::class, $seg);
        $this->assertSame(3, $seg->chapter);
        $this->assertSame(16, $seg->verse);
    }

    // ── Non-Latin script book names ────────────────────────────────

    public function testChineseBookNameParsesToAst(): void
    {
        // 創世記 = Genesis in Chinese
        $q = $this->parse('創世記1,5');
        $this->assertSame(0, $q->book); // placeholder until validator resolves
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRef::class, $seg);
        $this->assertSame(1, $seg->chapter);
        $this->assertSame(5, $seg->verse);
    }

    public function testArabicBookNameParsesRange(): void
    {
        // يوحنا = John in Arabic (RTL)
        $q = $this->parse('يوحنا3,16-18');
        $this->assertCount(1, $q->segments);
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRange::class, $seg);
        $this->assertSame(3, $seg->from->chapter);
        $this->assertSame(16, $seg->from->verse);
        $this->assertSame(3, $seg->to->chapter);
        $this->assertSame(18, $seg->to->verse);
    }

    public function testKoreanBookNameWholeChapter(): void
    {
        // 창세기 = Genesis in Korean
        $q = $this->parse('창세기1');
        $this->assertCount(1, $q->segments);
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRef::class, $seg);
        $this->assertSame(1, $seg->chapter);
        $this->assertNull($seg->verse);
    }

    public function testJapaneseBookNameCrossChapterRange(): void
    {
        // ヨハネ = John in Japanese (Katakana)
        $q = $this->parse('ヨハネ1,5-2,3');
        $this->assertCount(1, $q->segments);
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRange::class, $seg);
        $this->assertSame(1, $seg->from->chapter);
        $this->assertSame(5, $seg->from->verse);
        $this->assertSame(2, $seg->to->chapter);
        $this->assertSame(3, $seg->to->verse);
    }

    public function testArabicBookNameNonConsecutiveVerses(): void
    {
        // يوحنا3,16.18.20 → three individual verse refs
        $q = $this->parse('يوحنا3,16.18.20');
        $this->assertCount(3, $q->segments);

        $this->assertInstanceOf(VerseRef::class, $q->segments[0]);
        $this->assertSame(16, $q->segments[0]->verse);

        $this->assertInstanceOf(VerseRef::class, $q->segments[1]);
        $this->assertSame(18, $q->segments[1]->verse);

        $this->assertInstanceOf(VerseRef::class, $q->segments[2]);
        $this->assertSame(20, $q->segments[2]->verse);
    }

    public function testUrduBookNameParsesToAst(): void
    {
        // پیدائش = Genesis in Urdu (RTL Nastaliq)
        $q   = $this->parse('پیدائش1,1');
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRef::class, $seg);
        $this->assertSame(1, $seg->chapter);
        $this->assertSame(1, $seg->verse);
    }

    public function testTamilBookNameParsesToAst(): void
    {
        // ஆதியாகமம் = Genesis in Tamil (combining marks)
        $q   = $this->parse('ஆதியாகமம்1,1');
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRef::class, $seg);
        $this->assertSame(1, $seg->chapter);
        $this->assertSame(1, $seg->verse);
    }

    public function testAmharicBookNameChapterRange(): void
    {
        // ዘፍጥረት = Genesis in Amharic (Ge'ez script)
        $q = $this->parse('ዘፍጥረት1-3');
        $this->assertCount(1, $q->segments);
        $seg = $q->segments[0];
        $this->assertInstanceOf(VerseRange::class, $seg);
        $this->assertSame(1, $seg->from->chapter);
        $this->assertNull($seg->from->verse);
        $this->assertSame(3, $seg->to->chapter);
        $this->assertNull($seg->to->verse);
    }

    // ── Non-consecutive verse ranges ──────────────────────────────

    public function testNonConsecutiveVerseRanges(): void
    {
        // Genesis1,1-3.5-8 → range 1:1-3, then range 1:5-8
        $q = $this->parse('Genesis1,1-3.5-8');
        $this->assertCount(2, $q->segments);

        $this->assertInstanceOf(VerseRange::class, $q->segments[0]);
        $this->assertSame(1, $q->segments[0]->from->verse);
        $this->assertSame(3, $q->segments[0]->to->verse);

        $this->assertInstanceOf(VerseRange::class, $q->segments[1]);
        $this->assertSame(5, $q->segments[1]->from->verse);
        $this->assertSame(8, $q->segments[1]->to->verse);
    }
}
