<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Compiler;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;

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
 * A reference selects verses by their number: every row is stored at its
 * identity (chapter, verse), and where the edition prints it is recorded
 * apart in printed_chapter / verse_label (migrations 044/045), which only
 * matter to print mode, not to queries.
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
                $sql .= ' AND ' . $this->compileWhere($segment);
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
     * What each compiled query asks for, in compile()'s order: a SegmentRef for a
     * verse or a verse range within one chapter, null otherwise.
     *
     * @param string $bookAsWritten The book as the user wrote it ("Mk", "1Cor").
     * @param string $notation      'EUROPEAN' (chapter,verse) or anything else (chapter:verse).
     * @return array<int, ?\BibleGet\Api\Pipeline\SegmentRef>
     */
    public function segmentRefs(BibleQuery $query, string $bookAsWritten, string $notation): array
    {
        $sep  = $notation === 'EUROPEAN' ? ',' : ':';
        $refs = [];
        foreach ($query->segments as $segment) {
            [$from, $to] = $segment instanceof VerseRange ? [$segment->from, $segment->to] : [$segment, $segment];
            if ($from->verse === null || $to->verse === null || $from->chapter !== $to->chapter) {
                $refs[] = null;
                continue;
            }
            $refs[] = new \BibleGet\Api\Pipeline\SegmentRef(
                $query->book,
                $from->chapter,
                $from->verse,
                $to->verse,
                $bookAsWritten . ' ' . $from->chapter . $sep . $from->verse
                    . ( $to->verse !== $from->verse ? '-' . $to->verse : '' )
            );
        }
        return $refs;
    }

    /**
     * The segment's condition on chapter and verse, parenthesised so clauses
     * appended after it (preferorigin) bind to all of it.
     */
    private function compileWhere(VerseRange|VerseRef $segment): string
    {
        return '( ' . $this->compileSegment($segment, 'chapter', 'verse') . ' )';
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
