<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

use BibleGet\Api\Cbppedr\CatalogReader;

/**
 * The CBPPEDR catalogs as loaded (migration 072): persons, places and events, the CBTUDR units where each is
 * attested, and for a labelled unit the editions that name the entry there.
 *
 * @phpstan-import-type Entry from CatalogReader
 * @phpstan-type Ref array{book: int, chapter: int, verse: int, segment: ?string, tradition: ?string}
 * @phpstan-type IndexEntry array{id: string, kind: string, names: array<string, string>, description: array<string, string>, qid: ?string, qid_status: ?string, uncertain: bool, refs: list<Ref>}
 */
final class EntityRepository
{
    private const string TEXT_ARRAY = 'ARRAY(SELECT json_array_elements_text(?::json))';

    /** A link's ref: chapter, verse, a segment letter or two, a repeated printing (#2), the Esther text (@GREEK). */
    private const string REF = '/^(\d+):(\d+)([a-z]*)(?:#\d+)?(?:@(GREEK|HEBREW))?$/';

    /**
     * The verses of an edition where each entry of a type is attested: its CBTUDR units through the edition's rule
     * and reviewed links (drafts are not used), a labelled unit only where the label names this edition, in the
     * edition's order. Entries with no verse in the edition are left out.
     *
     * @return array{release: ?array{commit: string, loaded_at: string}, entries: list<IndexEntry>}
     */
    public function index(string $edition, string $type): array
    {
        $refs = $this->refsByEntry($edition, $type);
        return ['release' => $this->release(), 'entries' => $refs === [] ? [] : $this->entries($type, $refs)];
    }

    /**
     * Each entry's verses in the edition, in the edition's order.
     *
     * @return array<string, list<Ref>>
     */
    private function refsByEntry(string $edition, string $type): array
    {
        $stmt = $this->db->prepare(
            "WITH links AS (
                 SELECT l.book, l.ord, l.ref, u.unit
                 FROM btu_link l CROSS JOIN LATERAL unnest(l.units) AS u(unit)
                 WHERE l.edition = :edition AND l.status IN ('rule', 'reviewed')
             )
             SELECT a.entry, k.book, k.ord, k.ref
             FROM bpe_attested a
             JOIN bpe_entry e ON e.id = a.entry AND e.type = :type
             JOIN links k ON k.unit = a.unit
             LEFT JOIN bpe_label b ON b.entry = a.entry AND b.unit = a.unit
             WHERE b.entry IS NULL OR :edition = ANY(b.editions)
             GROUP BY a.entry, k.book, k.ord, k.ref
             ORDER BY a.entry, k.book, k.ord"
        );
        $stmt->execute(['edition' => $edition, 'type' => $type]);
        $refs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_string($row['entry'] ?? null) || !is_int($row['book'] ?? null) || !is_string($row['ref'] ?? null)) {
                continue;
            }
            $ref = self::ref($row['book'], $row['ref']);
            if ($ref !== null && !in_array($ref, $refs[$row['entry']] ?? [], true)) {
                $refs[$row['entry']][] = $ref;
            }
        }
        return $refs;
    }

    /**
     * The entries of a type that have verses, in id order, with them.
     *
     * @param array<string, list<Ref>> $refs
     * @return list<IndexEntry>
     */
    private function entries(string $type, array $refs): array
    {
        $entries = [];
        $rows    = $this->db->prepare(
            'SELECT id, kind, names::text AS names, description::text AS description, qid, qid_status, uncertain
             FROM bpe_entry WHERE type = ? ORDER BY id'
        );
        $rows->execute([$type]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (!is_array($r) || !is_string($r['id'] ?? null) || !isset($refs[$r['id']])) {
                continue;
            }
            $entries[] = [
                'id'          => $r['id'],
                'kind'        => is_string($r['kind'] ?? null) ? $r['kind'] : '',
                'names'       => self::stringMap($r['names'] ?? null),
                'description' => self::stringMap($r['description'] ?? null),
                'qid'         => is_string($r['qid'] ?? null) ? $r['qid'] : null,
                'qid_status'  => is_string($r['qid_status'] ?? null) ? $r['qid_status'] : null,
                'uncertain'   => ( $r['uncertain'] ?? null ) === true,
                'refs'        => $refs[$r['id']],
            ];
        }
        return $entries;
    }

    /** @return ?array{commit: string, loaded_at: string} */
    private function release(): ?array
    {
        $r   = $this->db->query('SELECT commit, loaded_at::text AS loaded_at FROM bpe_release ORDER BY id DESC LIMIT 1');
        $row = $r === false ? false : $r->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['commit'] ?? null) || !is_string($row['loaded_at'] ?? null)) {
            return null;
        }
        return ['commit' => $row['commit'], 'loaded_at' => $row['loaded_at']];
    }

    /** @return ?Ref */
    private static function ref(int $book, string $ref): ?array
    {
        if (preg_match(self::REF, $ref, $m) !== 1) {
            return null;
        }
        return [
            'book'      => $book,
            'chapter'   => (int) $m[1],
            'verse'     => (int) $m[2],
            'segment'   => $m[3] === '' ? null : $m[3],
            'tradition' => ( $m[4] ?? '' ) === '' ? null : $m[4],
        ];
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $json): array
    {
        $out = [];
        $v   = is_string($json) ? json_decode($json, true) : null;
        foreach (is_array($v) ? $v : [] as $k => $s) {
            if (is_string($k) && is_string($s)) {
                $out[$k] = $s;
            }
        }
        return $out;
    }

    public function __construct(private readonly \PDO $db)
    {
    }

    /**
     * Replaces every entry in one transaction and records the release loaded. Attested units CBTUDR does not know
     * (no btu_unit row) are stored all the same, and reported.
     *
     * @param list<Entry> $entries
     * @return array{entries: int, attested: int, labels: int, unknown_units: list<string>}
     */
    public function replaceAll(array $entries, string $commit): array
    {
        $counts = ['entries' => 0, 'attested' => 0, 'labels' => 0];
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM bpe_entry'); // cascades to bpe_attested and bpe_label
            $entry    = $this->db->prepare(
                'INSERT INTO bpe_entry (id, type, kind, names, description, qid, qid_status, uncertain) VALUES (?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?)'
            );
            $attested = $this->db->prepare('INSERT INTO bpe_attested (entry, unit, ord) VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
            $label    = $this->db->prepare('INSERT INTO bpe_label (entry, unit, editions) VALUES (?, ?, ' . self::TEXT_ARRAY . ')');
            foreach ($entries as $e) {
                $entry->execute([
                    $e['id'],
                    $e['type'],
                    $e['kind'],
                    self::json((object) $e['names']),
                    self::json((object) $e['description']),
                    $e['qid'],
                    $e['qid_status'],
                    $e['uncertain'] ? 'true' : 'false',
                ]);
                $counts['entries']++;
                foreach ($e['attested'] as $ord => $unit) {
                    $attested->execute([$e['id'], $unit, $ord]);
                    $counts['attested'] += $attested->rowCount();
                }
                foreach ($e['labels'] as $unit => $editions) {
                    $label->execute([$e['id'], $unit, self::json($editions)]);
                    $counts['labels']++;
                }
            }
            $unknown      = $this->db->query(
                'SELECT DISTINCT a.unit FROM bpe_attested a WHERE NOT EXISTS (SELECT 1 FROM btu_unit u WHERE u.id = a.unit) ORDER BY 1'
            );
            $unknownUnits = $unknown === false ? [] : array_values(array_filter($unknown->fetchAll(\PDO::FETCH_COLUMN), 'is_string'));
            $this->db->prepare('INSERT INTO bpe_release (commit, counts) VALUES (?, ?::jsonb)')
                ->execute([$commit, self::json($counts + ['unknown_units' => count($unknownUnits)])]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $counts + ['unknown_units' => $unknownUnits];
    }

    private static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
