<?php

declare(strict_types=1);

namespace BibleGet\Api\Cbppedr;

use BibleGet\Api\Repositories\EntityRepository;
use BibleGet\Api\Util\PlainTextMap;

/**
 * Loads an edition's CBPPEDR mentions (CatalogReader) into bpe_mention (migration 073), each against the edition's
 * row its unit stands for, with the positions converted from UTF-16 code units of the row's text_plain to code points
 * of its formatted text (PlainTextMap). Nothing is loaded that does not match the text as it is now:
 *  - no_row: the unit is not linked (rule or reviewed links only) to exactly one row of the edition; a key's
 *    @GREEK / @HEBREW suffix picks Esther's text, a key without one takes a link of either;
 *  - stale: the SHA-256 of the row's text_plain is not the unit's hash (the text changed since CBPPEDR read it);
 *    or, reported as "<key> text_plain", the row's text_plain is not the plain text PlainTextMap derives from its
 *    text (SQL's \s, which follows the database locale, took in a whitespace PlainTextMap keeps), so positions
 *    would shift;
 *  - bad_check: a span's text does not give its check, or falls outside the text;
 *  - unknown_ids: a mention names an entry, or a candidate, that is not loaded (ruling B: dropped whole).
 * A unit is reported by its key, a mention by its key and span ("btu:gn.12.1 10-15"), an unknown id after its key.
 * Each mention also keeps the SHA-256 of the row's formatted text (text_hash), which its positions count.
 * Runs inside the caller's transaction, after the entries are loaded (bpe_mention.entry references them).
 *
 * @phpstan-import-type Mention from CatalogReader
 * @phpstan-import-type MentionUnit from CatalogReader
 * @phpstan-import-type Ref from EntityRepository
 * @phpstan-type Report array{loaded: int, stale: list<string>, bad_check: list<string>, no_row: list<string>, unknown_ids: list<string>}
 */
final class MentionLoader
{
    /** The mentions inserted by one statement. */
    private const int BATCH = 1000;

    /**
     * @param array<string, MentionUnit> $mentions by unit key
     * @return Report
     */
    public static function load(\PDO $db, string $edition, array $mentions): array
    {
        $report = ['loaded' => 0, 'stale' => [], 'bad_check' => [], 'no_row' => [], 'unknown_ids' => []];
        if ($mentions === []) {
            return $report;
        }
        if (preg_match('/^[A-Z0-9_]+$/', $edition) !== 1) {
            throw new \InvalidArgumentException("Not an edition: $edition");
        }
        $known = self::knownIds($db);
        $links = self::links($db, $edition);
        $texts = self::texts($db, $edition);
        $rows  = [];
        foreach ($mentions as $key => $unit) {
            $row = self::row($key, $links, $texts);
            if ($row === null) {
                $report['no_row'][] = $key;
                continue;
            }
            [$ref, $text, $plain] = $row;
            if (!hash_equals(hash('sha256', $plain), $unit['hash'])) {
                $report['stale'][] = $key;
                continue;
            }
            $map = PlainTextMap::of($text);
            if ($map->plain() !== $plain) {
                $report['stale'][] = $key . ' text_plain';
                continue;
            }
            $utf16    = mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8');
            $textHash = hash('sha256', $text);
            foreach ($unit['mentions'] as $m) {
                $span = self::span($key, $m, $utf16, $map, $known, $report);
                if ($span === null) {
                    continue;
                }
                $rows[] = [
                    'edition'    => $edition,
                    'book'       => $ref[0],
                    'chapter'    => $ref[1],
                    'verse'      => $ref[2],
                    'segment'    => $ref[3],
                    'tradition'  => $ref[4],
                    'unit'       => $key,
                    'plain_hash' => $unit['hash'],
                    'text_hash'  => $textHash,
                    'start_cp'   => $span[0],
                    'end_cp'     => $span[1],
                    'entry'      => $m['entry'],
                    'candidates' => $m['candidates'],
                ];
            }
        }
        $report['loaded'] = self::insert($db, $rows);
        return $report;
    }

    /**
     * Inserts the mentions self::BATCH a statement, so that a load through an SSH tunnel is a few dozen round trips
     * rather than one per mention.
     *
     * @param list<array<string, mixed>> $rows
     * @return int the rows inserted
     */
    private static function insert(\PDO $db, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $insert   = $db->prepare(
            'INSERT INTO bpe_mention (edition, book, chapter, verse, segment, tradition, unit, plain_hash, text_hash, start_cp, end_cp, entry, candidates)
             SELECT edition, book, chapter, verse, segment, tradition, unit, plain_hash, text_hash, start_cp, end_cp, entry, candidates
               FROM json_to_recordset(?::json) AS r(edition varchar(16), book smallint, chapter integer, verse integer, segment text,
                    tradition text, unit text, plain_hash text, text_hash text, start_cp integer, end_cp integer, entry text, candidates text[])'
        );
        $inserted = 0;
        foreach (array_chunk($rows, self::BATCH) as $batch) {
            $insert->execute([json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $inserted += $insert->rowCount();
        }
        return $inserted;
    }

    /**
     * A mention's span in code points of the formatted text, or null (reported) when it cannot be loaded.
     *
     * @param Mention              $m
     * @param array<string, true>  $known
     * @param Report               $report
     * @return ?array{0: int, 1: int}
     */
    private static function span(string $key, array $m, string $utf16, PlainTextMap $map, array $known, array &$report): ?array
    {
        $unknown = array_values(array_filter($m['candidates'] ?? [$m['entry']], static fn (?string $id): bool => !isset($known[(string) $id])));
        if ($unknown !== []) {
            $report['unknown_ids'][] = $key . ' ' . implode(',', $unknown);
            return null;
        }
        $label = $key . ' ' . $m['start'] . '-' . $m['end'];
        $units = intdiv(strlen($utf16), 2);
        if ($m['start'] < 0 || $m['end'] <= $m['start'] || $m['end'] > $units) {
            $report['bad_check'][] = $label;
            return null;
        }
        $words = mb_convert_encoding(substr($utf16, $m['start'] * 2, ( $m['end'] - $m['start'] ) * 2), 'UTF-8', 'UTF-16LE');
        $span  = substr(hash('sha256', $words), 0, 8) === $m['check'] ? $map->toText($m['start'], $m['end']) : null;
        if ($span === null || $span[1] <= $span[0]) {
            $report['bad_check'][] = $label;
            return null;
        }
        return $span;
    }

    /**
     * The edition's row a unit key stands for, with its text and text_plain: null unless exactly one.
     *
     * @param array<string, list<Ref>>                        $links
     * @param array<string, ?array{0: string, 1: string}>     $texts
     * @return ?array{0: Ref, 1: string, 2: string}
     */
    private static function row(string $key, array $links, array $texts): ?array
    {
        $parts     = explode('@', $key, 2);
        $tradition = $parts[1] ?? null;
        $found     = [];
        foreach ($links[$parts[0]] ?? [] as $ref) {
            if ($tradition === null || $ref[4] === $tradition) {
                $found[self::key($ref[0], $ref[1], $ref[2], $ref[3], $ref[4])] = $ref;
            }
        }
        if (count($found) !== 1) {
            return null;
        }
        $text = $texts[array_key_first($found)] ?? null;
        return $text === null ? null : [reset($found), $text[0], $text[1]];
    }

    /** @return array<string, true> */
    private static function knownIds(\PDO $db): array
    {
        $stmt = $db->query('SELECT id FROM bpe_entry');
        $ids  = $stmt === false ? [] : array_filter($stmt->fetchAll(\PDO::FETCH_COLUMN), 'is_string');
        return array_fill_keys($ids, true);
    }

    /**
     * The verses each unit is linked to in the edition, by rule and reviewed links.
     *
     * @return array<string, list<Ref>>
     */
    private static function links(\PDO $db, string $edition): array
    {
        $stmt = $db->prepare(
            "SELECT l.book, l.ref, u.unit FROM btu_link l CROSS JOIN LATERAL unnest(l.units) AS u(unit)
             WHERE l.edition = ? AND l.status IN ('rule', 'reviewed')"
        );
        $stmt->execute([$edition]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (!is_array($r) || !is_int($r['book'] ?? null) || !is_string($r['ref'] ?? null) || !is_string($r['unit'] ?? null)) {
                continue;
            }
            $ref = EntityRepository::ref($r['book'], $r['ref']);
            if ($ref !== null) {
                $out[$r['unit']][] = $ref;
            }
        }
        return $out;
    }

    /**
     * The edition's rows (text, text_plain), keyed as self::key; null where two rows share a key.
     *
     * @return array<string, ?array{0: string, 1: string}>
     */
    private static function texts(\PDO $db, string $edition): array
    {
        $stmt = $db->query('SELECT book, chapter, verse, verse_segment, text_tradition, text, text_plain FROM "' . $edition . '"');
        $out  = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (!is_array($r) || !is_int($r['book'] ?? null) || !is_int($r['chapter'] ?? null) || !is_int($r['verse'] ?? null)) {
                continue;
            }
            $segment   = is_string($r['verse_segment'] ?? null) ? $r['verse_segment'] : null;
            $tradition = is_string($r['text_tradition'] ?? null) ? $r['text_tradition'] : null;
            $key       = self::key($r['book'], $r['chapter'], $r['verse'], $segment, $tradition);
            $out[$key] = array_key_exists($key, $out) ? null : [is_string($r['text'] ?? null) ? $r['text'] : '', is_string($r['text_plain'] ?? null) ? $r['text_plain'] : ''];
        }
        return $out;
    }

    private static function key(int $book, int $chapter, int $verse, ?string $segment, ?string $tradition): string
    {
        return $book . '|' . $chapter . '|' . $verse . '|' . ( $segment ?? '' ) . '|' . ( $tradition ?? '' );
    }
}
