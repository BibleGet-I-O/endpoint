<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

/**
 * The verse number an edition prints, when it differs from where the row is stored.
 *
 * NABRE prints Wis 12:1 at the end of chapter 11 (after 11:26) and the first
 * words of Job 10:1 at the end of chapter 9. The rows are stored where they
 * are printed, with the printed label in `verseequiv` (`12:1`, `10:1a`). A
 * reference names a verse by its number, so every endpoint selects and
 * reports such a row by its cited chapter and verse.
 *
 * This class is the only place that parses `verseequiv` for this purpose:
 * the SQL half builds WHERE clauses, the PHP half maps fetched rows. When
 * the placement columns of #184 land, both halves switch to them.
 */
final class PrintedLabel
{
    /**
     * POSIX regex (PostgreSQL `~`) for a `chapter:verse` label, optionally
     * lettered. At most three digits each (150 chapters, 176 verses), so the
     * ::int casts below can never overflow.
     */
    public const string PATTERN = '^[0-9]{1,3}:[0-9]{1,3}[a-z]*$';

    /** True for a row whose printed label names another chapter:verse. */
    public static function sqlLabelled(): string
    {
        return "(coalesce(verseequiv, '') ~ '" . self::PATTERN . "')";
    }

    /** The chapter a row's verse belongs to. */
    public static function sqlChapter(): string
    {
        return '(CASE WHEN ' . self::sqlLabelled()
            . " THEN split_part(verseequiv, ':', 1)::int ELSE chapter END)";
    }

    /** The verse number a row carries. */
    public static function sqlVerse(): string
    {
        return '(CASE WHEN ' . self::sqlLabelled()
            . " THEN substring(split_part(verseequiv, ':', 2) from '^[0-9]+')::int ELSE verse END)";
    }

    /**
     * @param array<string, mixed> $row A fetched verse row (chapter, verse, verseequiv).
     * @return array{chapter: int, verse: int}
     */
    public static function cited(array $row): array
    {
        $equiv = $row['verseequiv'] ?? null;
        if (is_string($equiv) && preg_match('/^(\d{1,3}):(\d{1,3})[a-z]*\z/', $equiv, $m) === 1) {
            return ['chapter' => (int) $m[1], 'verse' => (int) $m[2]];
        }
        return [
            'chapter' => is_numeric($row['chapter'] ?? null) ? (int) $row['chapter'] : 0,
            'verse'   => is_numeric($row['verse'] ?? null) ? (int) $row['verse'] : 0,
        ];
    }
}
