<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Compiler;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\Compiler\SqlCompiler;
use BibleGet\Api\Util\PrintedLabel;
use PHPUnit\Framework\TestCase;

final class SqlCompilerTest extends TestCase
{
    private SqlCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new SqlCompiler();
    }

    /**
     * @return array<int, string>
     */
    private function compile(BibleQuery $query, string $version = 'CEI2008', string $preferOrigin = ''): array
    {
        return $this->compiler->compile($query, $version, $preferOrigin, [], []);
    }

    /**
     * The WHERE fragment for one segment: rows stored at the place selected
     * (and not labelled elsewhere), or rows labelled elsewhere whose printed
     * chapter:verse is in the selection. `{c}`/`{v}` stand for the chapter
     * and verse expressions.
     */
    private static function where(string $cond): string
    {
        $stored = str_replace(['{c}', '{v}'], ['chapter', 'verse'], $cond);
        $cited  = str_replace(['{c}', '{v}'], [PrintedLabel::sqlChapter(), PrintedLabel::sqlVerse()], $cond);
        return ' AND ( ( ' . $stored . ' AND NOT ' . PrintedLabel::sqlLabelled() . ' ) OR ( '
            . PrintedLabel::sqlLabelled() . ' AND ' . $cited . ' ) )';
    }

    // ── Single verse ──────────────────────────────────────────────

    public function testSingleVerse(): void
    {
        $query  = new BibleQuery(1, [new VerseRef(1, 3, null, 16)]);
        $result = $this->compile($query);

        $this->assertCount(1, $result);
        $this->assertSame(
            'SELECT * FROM "CEI2008" WHERE book = 1' . self::where('{c} = 3 AND {v} = 16') . ' ORDER BY "verseID"',
            $result[0]
        );
    }

    // ── Whole chapter ─────────────────────────────────────────────

    public function testWholeChapter(): void
    {
        $query  = new BibleQuery(1, [new VerseRef(1, 5)]);
        $result = $this->compile($query);

        $this->assertSame(
            'SELECT * FROM "CEI2008" WHERE book = 1' . self::where('{c} = 5') . ' ORDER BY "verseID"',
            $result[0]
        );
    }

    // ── Verse range (same chapter) ────────────────────────────────

    public function testSameChapterVerseRange(): void
    {
        $from   = new VerseRef(1, 3, null, 1);
        $to     = new VerseRef(1, 3, null, 10);
        $query  = new BibleQuery(1, [new VerseRange($from, $to)]);
        $result = $this->compile($query);

        $this->assertSame(
            'SELECT * FROM "CEI2008" WHERE book = 1' . self::where('{c} = 3 AND {v} >= 1 AND {v} <= 10') . ' ORDER BY "verseID"',
            $result[0]
        );
    }

    // ── Chapter range ─────────────────────────────────────────────

    public function testChapterRange(): void
    {
        $from   = new VerseRef(1, 1);
        $to     = new VerseRef(1, 3);
        $query  = new BibleQuery(1, [new VerseRange($from, $to)]);
        $result = $this->compile($query);

        $this->assertSame(
            'SELECT * FROM "CEI2008" WHERE book = 1' . self::where('{c} >= 1 AND {c} <= 3') . ' ORDER BY "verseID"',
            $result[0]
        );
    }

    // ── Cross-chapter range ───────────────────────────────────────

    public function testCrossChapterRange(): void
    {
        $from   = new VerseRef(1, 1, null, 5);
        $to     = new VerseRef(1, 2, null, 3);
        $query  = new BibleQuery(1, [new VerseRange($from, $to)]);
        $result = $this->compile($query);

        $this->assertSame(
            'SELECT * FROM "CEI2008" WHERE book = 1' . self::where('( ( {c} = 1 AND {v} >= 5 ) OR ( {c} = 2 AND {v} <= 3 ) )') . ' ORDER BY "verseID"',
            $result[0]
        );
    }

    // ── Cross-chapter range with intermediate chapters ────────────

    public function testCrossChapterRangeWithIntermediateChapters(): void
    {
        $from   = new VerseRef(1, 1, null, 5);
        $to     = new VerseRef(1, 4, null, 3);
        $query  = new BibleQuery(1, [new VerseRange($from, $to)]);
        $result = $this->compile($query);

        $this->assertSame(
            'SELECT * FROM "CEI2008" WHERE book = 1' . self::where('( ( {c} = 1 AND {v} >= 5 ) OR ( {c} = 2 ) OR ( {c} = 3 ) OR ( {c} = 4 AND {v} <= 3 ) )') . ' ORDER BY "verseID"',
            $result[0]
        );
    }

    // ── Non-consecutive verses → multiple queries ─────────────────

    public function testNonConsecutiveVerses(): void
    {
        // Genesis1,1-3.5.10 → 3 segments → 3 SQL queries
        $query  = new BibleQuery(1, [
            new VerseRange(new VerseRef(1, 1, null, 1), new VerseRef(1, 1, null, 3)),
            new VerseRef(1, 1, null, 5),
            new VerseRef(1, 1, null, 10),
        ]);
        $result = $this->compile($query);

        $this->assertCount(3, $result);
        $this->assertStringContainsString('verse >= 1 AND verse <= 3', $result[0]);
        $this->assertStringContainsString('verse = 5', $result[1]);
        $this->assertStringContainsString('verse = 10', $result[2]);
    }

    // ── preferOrigin annotation ───────────────────────────────────

    public function testPreferOriginBindsToWholeCondition(): void
    {
        $query  = new BibleQuery(23, [new VerseRef(23, 51, null, 3)]);
        $result = $this->compile($query, 'TEST3', " AND verseorigin = 'HEBREW'");

        $this->assertSame(
            'SELECT * FROM "TEST3" WHERE book = 23' . self::where('{c} = 51 AND {v} = 3')
            . " AND verseorigin = 'HEBREW' ORDER BY \"verseID\"",
            $result[0]
        );
    }

    public function testSingleVerseMatchesPrintedLabel(): void
    {
        $query  = new BibleQuery(27, [new VerseRef(27, 12, null, 1)]);
        $result = $this->compile($query, 'TEST3');

        $this->assertStringContainsString('chapter = 12 AND verse = 1 AND NOT ' . PrintedLabel::sqlLabelled(), $result[0]);
        $this->assertStringContainsString(PrintedLabel::sqlChapter() . ' = 12 AND ' . PrintedLabel::sqlVerse() . ' = 1', $result[0]);
    }

    public function testPreferOrigin(): void
    {
        $query  = new BibleQuery(19, [new VerseRef(19, 51, null, 1)]);
        $result = $this->compiler->compile($query, 'CEI2008', " AND verseorigin = 'GREEK'", [], []);

        $this->assertStringContainsString("AND verseorigin = 'GREEK'", $result[0]);
    }

    // ── Copyright LIMIT 30 ────────────────────────────────────────

    public function testCopyrightLimit(): void
    {
        $query  = new BibleQuery(1, [new VerseRef(1, 1)]);
        $result = $this->compiler->compile($query, 'NABRE', '', ['NABRE'], []);

        $this->assertStringEndsWith('LIMIT 30', $result[0]);
    }

    public function testNoCopyrightLimitForNonCopyrighted(): void
    {
        $query  = new BibleQuery(1, [new VerseRef(1, 1)]);
        $result = $this->compiler->compile($query, 'CEI2008', '', ['NABRE'], []);

        $this->assertStringEndsWith('ORDER BY "verseID"', $result[0]);
    }

    public function testForcedCopyrightLimit(): void
    {
        $query  = new BibleQuery(1, [new VerseRef(1, 1)]);
        $result = $this->compiler->compile($query, 'CUSTOM', '', [], ['CUSTOM']);

        $this->assertStringEndsWith('LIMIT 30', $result[0]);
    }
}
