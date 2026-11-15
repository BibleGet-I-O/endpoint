<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Compiler;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Util\PrintedLabel;

/**
 * Walks the BibleQuery AST to produce SQL WHERE clauses.
 *
 * Handles:
 * - Single verse, whole chapter, verse ranges (same-chapter and cross-chapter)
 * - Non-consecutive verses (multiple segments → multiple SQL queries)
 * - preferorigin annotation for Psalms
 * - Copyright-limited verse count (LIMIT 30)
 * - ORDER BY verseID
 *
 * A reference selects verses by the number the edition prints: a row that
 * carries a chapter:verse label printed in another chapter (NABRE Wis 12:1
 * after 11:26) is matched by that label, not by where it is stored. See
 * PrintedLabel.
 */
final class SqlCompiler
{
    /**
     * Compile a BibleQuery AST into one or more SQL SELECT statements.
     *
     * Non-consecutive segments (separated by "." in the original notation)
     * produce separate SQL queries, each independently finalized with
     * ORDER BY and optional LIMIT.
     *
     * @param string|array<int, string> $preferOrigin  Single string (applied to all segments)
     *                                                  or per-segment array of preferOrigin suffixes
     * @param array<int, string> $copyrightVersions
     * @param array<int, string> $requestedCopyrightedVersions
     * @return array<int, string> SQL queries
     */
    public function compile(
        BibleQuery $query,
        string $version,
        string|array $preferOrigin,
        array $copyrightVersions,
        array $requestedCopyrightedVersions,
    ): array {
        $sqlBase  = 'SELECT * FROM "' . $version . '" WHERE book = ' . $query->book;
        $queries  = [];
        $isCopied = in_array($version, $copyrightVersions)
            || in_array($version, $requestedCopyrightedVersions);

        foreach ($query->segments as $i => $segment) {
            $sql = $sqlBase;

            if ($segment instanceof VerseRange || $segment instanceof VerseRef) {
                $sql .= ' AND ' . $this->compileBothPlacements($segment);
            }

            $segmentOrigin = is_array($preferOrigin)
                ? ( $preferOrigin[$i] ?? '' )
                : $preferOrigin;
            $sql          .= $segmentOrigin;
            $sql          .= ' ORDER BY "verseID"';

            if ($isCopied) {
                $sql .= ' LIMIT 30';
            }

            $queries[] = $sql;
        }

        return $queries;
    }

    /**
     * Rows stored at the selected place (unless labelled elsewhere), or rows
     * labelled elsewhere whose printed chapter:verse is selected. Wrapped in
     * parentheses so clauses appended after it (preferorigin) bind to both.
     */
    private function compileBothPlacements(VerseRange|VerseRef $segment): string
    {
        $labelled = PrintedLabel::sqlLabelled();
        $stored   = $this->compileSegment($segment, 'chapter', 'verse');
        $cited    = $this->compileSegment($segment, PrintedLabel::sqlChapter(), PrintedLabel::sqlVerse());
        return '( ( ' . $stored . ' AND NOT ' . $labelled . ' ) OR ( ' . $labelled . ' AND ' . $cited . ' ) )';
    }

    private function compileSegment(VerseRange|VerseRef $segment, string $c, string $v): string
    {
        return $segment instanceof VerseRange
            ? $this->compileVerseRange($segment, $c, $v)
            : $this->compileVerseRef($segment, $c, $v);
    }

    private function compileVerseRef(VerseRef $ref, string $c, string $v): string
    {
        if ($ref->verse === null) {
            // Whole chapter
            return $c . ' = ' . $ref->chapter;
        }
        return $c . ' = ' . $ref->chapter . ' AND ' . $v . ' = ' . $ref->verse;
    }

    private function compileVerseRange(VerseRange $range, string $c, string $v): string
    {
        $from = $range->from;
        $to   = $range->to;

        // Chapter range (no verses specified)
        if ($from->verse === null && $to->verse === null) {
            return $c . ' >= ' . $from->chapter . ' AND ' . $c . ' <= ' . $to->chapter;
        }

        // Same chapter verse range
        if ($from->chapter === $to->chapter) {
            $sql = $c . ' = ' . $from->chapter;
            if ($from->verse !== null) {
                $sql .= ' AND ' . $v . ' >= ' . $from->verse;
            }
            if ($to->verse !== null) {
                $sql .= ' AND ' . $v . ' <= ' . $to->verse;
            }
            return $sql;
        }

        // Cross-chapter range
        return $this->compileCrossChapterRange($from, $to, $c, $v);
    }

    private function compileCrossChapterRange(VerseRef $from, VerseRef $to, string $c, string $v): string
    {
        $sql = '( ( ' . $c . ' = ' . $from->chapter;
        if ($from->verse !== null) {
            $sql .= ' AND ' . $v . ' >= ' . $from->verse;
        }
        $sql .= ' )';

        // Intermediate chapters (full chapters between from and to)
        for ($ch = $from->chapter + 1; $ch < $to->chapter; $ch++) {
            $sql .= ' OR ( ' . $c . ' = ' . $ch . ' )';
        }

        $sql .= ' OR ( ' . $c . ' = ' . $to->chapter;
        if ($to->verse !== null) {
            $sql .= ' AND ' . $v . ' <= ' . $to->verse;
        }
        $sql .= ' ) )';

        return $sql;
    }
}
