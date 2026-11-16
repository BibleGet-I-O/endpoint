<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

/**
 * How an edition prints a verse row (migrations 044/045), normalized for
 * responses: every quote and search result row carries these three fields.
 *
 * - printed_chapter: the chapter under whose heading the row is printed, when
 *   not its own (NABRE Wis 12:1 under 11, John 7:53 under 8); null otherwise.
 * - verse_label: the label printed beside the row when it is not simply the
 *   verse number ("12:1", "7:53"; "" for a part printed without a number).
 * - omitted: the edition prints the number without text (#180).
 */
final class Placement
{
    /**
     * A search result row's verse identity and placement: chapter, verse,
     * verse_segment (the printed letter), alt_ref (the position in another
     * numbering, e.g. CEI 2008's Greek psalm number), and
     * the three placement fields.
     *
     * @param array<string, mixed> $row A fetched verse row.
     * @return array{chapter: int, verse: int, verse_segment: ?string, alt_ref: ?string, printed_chapter: ?int, verse_label: ?string, omitted: bool}
     */
    public static function verseFields(array $row): array
    {
        return [
            'chapter'       => is_numeric($row['chapter'] ?? null) ? (int) $row['chapter'] : 0,
            'verse'         => is_numeric($row['verse'] ?? null) ? (int) $row['verse'] : 0,
            'verse_segment' => is_string($row['verse_segment'] ?? null) ? $row['verse_segment'] : null,
            'alt_ref'       => is_string($row['alt_ref'] ?? null) ? $row['alt_ref'] : null,
        ] + self::fromRow($row);
    }

    /**
     * @param array<string, mixed> $row A fetched verse row.
     * @return array{printed_chapter: ?int, verse_label: ?string, omitted: bool}
     */
    public static function fromRow(array $row): array
    {
        $omitted = $row['omitted'] ?? false;
        return [
            'printed_chapter' => is_numeric($row['printed_chapter'] ?? null) ? (int) $row['printed_chapter'] : null,
            'verse_label'     => is_string($row['verse_label'] ?? null) ? $row['verse_label'] : null,
            'omitted'         => $omitted === true || $omitted === 't' || $omitted === 1 || $omitted === '1',
        ];
    }
}
