<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Transform;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;

/**
 * AST→AST transformation that swaps the primary chapter with the alternate
 * chapter for Psalm references in Vulgate-numbered versions (VGCL, DRB).
 *
 * When a user queries "Psalm51(50),1":
 *  - For VGCL/DRB (Vulgate numbering): SQL should use chapter 50
 *  - For other versions (Hebrew numbering): SQL should use chapter 51
 *
 * This is a pure function of the AST — no database access, no SQL.
 */
final class PsalmChapterSwapper
{
    private const PSALMS_BOOK_NUM = 23;

    /** Versions that use Vulgate/LXX psalm numbering */
    private const VULGATE_VERSIONS = ['VGCL', 'DRB'];

    /**
     * Swap chapter ↔ alternateChapter for Psalm queries targeting VGCL/DRB.
     *
     * Only swaps segments where alternateChapter is set; segments without
     * an alternate are left unchanged.
     */
    public function swap(BibleQuery $query, string $version): BibleQuery
    {
        if ($query->book !== self::PSALMS_BOOK_NUM) {
            return $query;
        }
        if (!in_array($version, self::VULGATE_VERSIONS, true)) {
            return $query;
        }

        $hasAlternate = false;
        foreach ($query->segments as $segment) {
            if ($segment instanceof VerseRef && $segment->alternateChapter !== null) {
                $hasAlternate = true;
                break;
            }
            if ($segment instanceof VerseRange && ( $segment->from->alternateChapter !== null || $segment->to->alternateChapter !== null )) {
                $hasAlternate = true;
                break;
            }
        }
        if (!$hasAlternate) {
            return $query;
        }

        $newSegments = [];
        foreach ($query->segments as $segment) {
            if ($segment instanceof VerseRef) {
                $newSegments[] = $this->swapVerseRef($segment);
            } elseif ($segment instanceof VerseRange) {
                $newSegments[] = new VerseRange(
                    $this->swapVerseRef($segment->from),
                    $this->swapVerseRef($segment->to),
                );
            } else {
                $newSegments[] = $segment;
            }
        }

        return new BibleQuery($query->book, $newSegments);
    }

    private function swapVerseRef(VerseRef $ref): VerseRef
    {
        if ($ref->alternateChapter === null) {
            return $ref;
        }

        return new VerseRef(
            $ref->book,
            $ref->alternateChapter,
            $ref->chapter,
            $ref->verse,
            $ref->partialSuffix,
            $ref->position,
        );
    }
}
