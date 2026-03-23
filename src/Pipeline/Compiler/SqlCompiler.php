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

            if ($segment instanceof VerseRange) {
                $sql .= $this->compileVerseRange($segment);
            } elseif ($segment instanceof VerseRef) {
                $sql .= $this->compileVerseRef($segment);
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

    private function compileVerseRef(VerseRef $ref): string
    {
        if ($ref->verse === null) {
            // Whole chapter
            return ' AND chapter = ' . $ref->chapter;
        }
        return ' AND chapter = ' . $ref->chapter . ' AND verse = ' . $ref->verse;
    }

    private function compileVerseRange(VerseRange $range): string
    {
        $from = $range->from;
        $to   = $range->to;

        // Chapter range (no verses specified)
        if ($from->verse === null && $to->verse === null) {
            return ' AND chapter >= ' . $from->chapter . ' AND chapter <= ' . $to->chapter;
        }

        // Same chapter verse range
        if ($from->chapter === $to->chapter) {
            $sql = ' AND chapter = ' . $from->chapter;
            if ($from->verse !== null) {
                $sql .= ' AND verse >= ' . $from->verse;
            }
            if ($to->verse !== null) {
                $sql .= ' AND verse <= ' . $to->verse;
            }
            return $sql;
        }

        // Cross-chapter range
        return $this->compileCrossChapterRange($from, $to);
    }

    private function compileCrossChapterRange(VerseRef $from, VerseRef $to): string
    {
        $sql = ' AND ( ( chapter = ' . $from->chapter;
        if ($from->verse !== null) {
            $sql .= ' AND verse >= ' . $from->verse;
        }
        $sql .= ' )';

        // Intermediate chapters (full chapters between from and to)
        for ($ch = $from->chapter + 1; $ch < $to->chapter; $ch++) {
            $sql .= ' OR ( chapter = ' . $ch . ' )';
        }

        $sql .= ' OR ( chapter = ' . $to->chapter;
        if ($to->verse !== null) {
            $sql .= ' AND verse <= ' . $to->verse;
        }
        $sql .= ' ) )';

        return $sql;
    }
}
