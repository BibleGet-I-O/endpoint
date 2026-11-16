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
