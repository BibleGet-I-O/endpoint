<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Transform;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;

/**
 * Resolves a Psalm reference to the chapter and verse an edition stores it under (#46, #210).
 *
 * Psalm numbers are read as **Hebrew** numbers, the numbering the Nova Vulgata (NVBSE) and the
 * modern translations use. When a reference gives two numbers, "Psalm 89 (88)" or
 * "Salmo 88 (89)", the Hebrew number is the larger of the two, whichever is written inside the
 * parentheses: the Vulgate (Septuagint) number is never larger than the Hebrew one. So a
 * citation can be copied from a lectionary that prints the Vulgate number first (CEI, AELF, the
 * Latin lectionary) or the Hebrew number first (the Nova Vulgata, the Dutch lectionary).
 *
 * Hebrew-numbered editions are queried by the Hebrew number. Vulgate-numbered editions (VGCL,
 * DRB) are queried by its Vulgate equivalent, chapter AND verse, because where the two
 * numberings split or join a psalm the verses move as well:
 *
 *   | Hebrew          | Vulgate           |
 *   |-----------------|-------------------|
 *   | 1–8, 148–150    | same              |
 *   | 9:1-21          | 9:1-21            |
 *   | 10:1-18         | 9:22-39           |
 *   | 11–113          | chapter − 1       |
 *   | 114:1-8         | 113:1-8           |
 *   | 115:1-18        | 113:9-26          |
 *   | 116:1-9         | 114:1-9           |
 *   | 116:10-19       | 115:1-10 (VGCL)   |
 *   |                 | 115:10-19 (DRB)   |
 *   | 117–146         | chapter − 1       |
 *   | 147:1-11        | 146:1-11          |
 *   | 147:12-20       | 147:1-9 (VGCL)    |
 *   |                 | 147:12-20 (DRB)   |
 *
 * The Douay-Rheims keeps the Hebrew verse numbers in the second halves of 116 and 147, where
 * the Clementine Vulgate restarts at 1 (tests/fixtures/versification, case drb-psalm-115-147).
 * The table is checked against each edition's verse snapshot, and agrees with vatican.va's Nova
 * Vulgata headings ("PSALMUS 10 (Vg 9, 22-39)", "PSALMUS 116 (114, 1-9; 115)"). A whole psalm
 * in one of those zones becomes the verse span it occupies, e.g. Hebrew 116 → VGCL 114:1-115:10.
 *
 * Elsewhere the verse number is kept as it is. The Clementine versification differs from the
 * Hebrew one in places (its Ps 15 has 10 verses, where Hebrew 16 and the Nova Vulgata have 11),
 * and those differences are not mapped.
 *
 * Pure function of the AST: no database access, no SQL.
 */
final class PsalmNumbering
{
    public const PSALMS_BOOK_NUM = 23;

    /** Editions that store the Psalms under their Vulgate (Septuagint) numbers. */
    public const VULGATE_VERSIONS = ['VGCL', 'DRB'];

    /**
     * Hebrew psalms that the Vulgate splits or joins, as spans of Hebrew verses:
     * Hebrew chapter => list of [first Hebrew verse, last Hebrew verse, Vulgate chapter, verse offset].
     * As the Clementine Vulgate (VGCL) numbers them; DRB_SPANS overrides where the Douay-Rheims differs.
     */
    private const SPANS = [
        9   => [[1, 21, 9, 0]],
        10  => [[1, 18, 9, 21]],
        114 => [[1, 8, 113, 0]],
        115 => [[1, 18, 113, 8]],
        116 => [[1, 9, 114, 0], [10, 19, 115, -9]],
        147 => [[1, 11, 146, 0], [12, 20, 147, -11]],
    ];

    /** The Douay-Rheims keeps the Hebrew verse numbers of 116:10-19 and 147:12-20. */
    private const DRB_SPANS = [
        116 => [[1, 9, 114, 0], [10, 19, 115, 0]],
        147 => [[1, 11, 146, 0], [12, 20, 147, 0]],
    ];

    /**
     * The Hebrew number of a Psalm reference: its only number, or the larger of its two.
     */
    public static function hebrewChapter(VerseRef $ref): int
    {
        return $ref->alternateChapter === null
            ? $ref->chapter
            : max($ref->chapter, $ref->alternateChapter);
    }

    public static function isVulgateNumbered(string $version): bool
    {
        return in_array($version, self::VULGATE_VERSIONS, true);
    }

    /**
     * The query with every Psalm reference resolved to the chapter and verse $version stores
     * it under, and no alternate chapter left. Any other book is returned unchanged.
     */
    public function resolve(BibleQuery $query, string $version): BibleQuery
    {
        if ($query->book !== self::PSALMS_BOOK_NUM) {
            return $query;
        }

        $vulgate  = self::isVulgateNumbered($version);
        $spans    = $version === 'DRB' ? self::DRB_SPANS + self::SPANS : self::SPANS;
        $segments = [];
        foreach ($query->segments as $segment) {
            if ($segment instanceof VerseRange) {
                $segments[] = new VerseRange(
                    $this->resolveRef($segment->from, $vulgate, $spans, true),
                    $this->resolveRef($segment->to, $vulgate, $spans, false),
                );
                continue;
            }

            $hebrew = self::hebrewChapter($segment);
            if ($vulgate && $segment->verse === null && isset($spans[$hebrew])) {
                // A whole psalm that the Vulgate splits or joins: the verse span it occupies.
                $segments[] = new VerseRange(
                    $this->resolveRef($segment, true, $spans, true),
                    $this->resolveRef($segment, true, $spans, false),
                );
                continue;
            }
            $segments[] = $this->resolveRef($segment, $vulgate, $spans, true);
        }

        return new BibleQuery($query->book, $segments);
    }

    /**
     * @param array<int, list<array{int, int, int, int}>> $spans The split zones of the edition.
     * @param bool $isStart Whether $ref opens a span; a whole chapter in a split zone then
     *                      maps to its first verse, otherwise to its last.
     */
    private function resolveRef(VerseRef $ref, bool $vulgate, array $spans, bool $isStart): VerseRef
    {
        $hebrew = self::hebrewChapter($ref);

        if (!$vulgate) {
            return $this->ref($ref, $hebrew, $ref->verse);
        }

        $zone = $spans[$hebrew] ?? null;
        if ($zone === null) {
            return $this->ref($ref, self::vulgateChapter($hebrew), $ref->verse);
        }

        $verse = $ref->verse ?? ( $isStart ? $zone[0][0] : $zone[count($zone) - 1][1] );
        $span  = $zone[count($zone) - 1];
        foreach ($zone as $candidate) {
            if ($verse <= $candidate[1]) {
                $span = $candidate;
                break;
            }
        }

        return $this->ref($ref, $span[2], $verse + $span[3]);
    }

    /**
     * Vulgate number of a Hebrew psalm outside the split zones.
     */
    private static function vulgateChapter(int $hebrew): int
    {
        return ( $hebrew >= 11 && $hebrew <= 113 ) || ( $hebrew >= 117 && $hebrew <= 146 )
            ? $hebrew - 1
            : $hebrew;
    }

    private function ref(VerseRef $ref, int $chapter, ?int $verse): VerseRef
    {
        return new VerseRef($ref->book, $chapter, null, $verse, $ref->partialSuffix, $ref->position);
    }
}
