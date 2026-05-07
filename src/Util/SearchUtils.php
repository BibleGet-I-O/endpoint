<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

use BibleGet\Api\Http\Exception\ValidationException;

/**
 * Utilities shared across search handlers (and `/quote`) for parsing the
 * comma-separated `version=` parameter and emitting subverse-aware
 * `canonical_order` per response.
 */
class SearchUtils
{
    /**
     * Parse a comma-separated `version=` parameter into a list of trimmed,
     * uppercased sigla, preserving first-occurrence order and dropping
     * duplicates. Mirrors the convention already used by `/v3/quote`.
     *
     * @return list<string>
     * @throws ValidationException when the parameter is missing or empty
     */
    public static function parseVersionsParam(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new ValidationException('The version parameter is required.');
        }

        $parts  = array_map('trim', explode(',', strtoupper($raw)));
        $unique = [];
        $seen   = [];
        foreach ($parts as $v) {
            if ($v !== '' && !isset($seen[$v])) {
                $seen[$v] = true;
                $unique[] = $v;
            }
        }

        if ($unique === []) {
            throw new ValidationException('The version parameter is required.');
        }

        return $unique;
    }

    /**
     * Assign a per-version 1-based `canonical_order` to each row, derived
     * from the row's `verseID` (the subverse-aware canonical-order key the
     * server has always used internally). Removes `verseID` from each row
     * after ranking, so the column never leaves the API boundary.
     *
     * Within each `version` partition rows are ranked 1..N in ascending
     * `verseID` order. The original $rows order is preserved — only the
     * `canonical_order` field is added (and `verseID` is removed).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function assignCanonicalOrder(array &$rows): void
    {
        $rankByIndex = [];
        $byVersion   = [];

        foreach ($rows as $i => $row) {
            $version                 = is_string($row['version'] ?? null) ? $row['version'] : '';
            $verseID                 = is_numeric($row['verseID'] ?? null) ? (int) $row['verseID'] : 0;
            $byVersion[$version][$i] = $verseID;
        }

        foreach ($byVersion as $verseIDsByIndex) {
            asort($verseIDsByIndex, SORT_NUMERIC);
            $rank = 1;
            foreach (array_keys($verseIDsByIndex) as $idx) {
                $rankByIndex[$idx] = $rank++;
            }
        }

        foreach ($rows as $i => &$row) {
            $row['canonical_order'] = $rankByIndex[$i] ?? 0;
            unset($row['verseID']);
        }
    }
}
