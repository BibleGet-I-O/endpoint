<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

use BibleGet\Api\Cbppedr\CatalogReader;
use BibleGet\Api\Cbppedr\MentionLoader;
use BibleGet\Api\Util\Placement;

/**
 * The CBPPEDR catalogs as loaded (migration 072): persons, places and events, the CBTUDR units where each is
 * attested, and for a labelled unit the editions that name the entry there. A verse in an index (Ref) is the tuple
 * [book, chapter, verse, segment, tradition]: compact, since an edition's index of persons runs to some 12,000 of them;
 * a verse the edition prints with its own label or under another chapter's heading adds [label, printed chapter].
 *
 * @phpstan-import-type Entry from CatalogReader
 * @phpstan-import-type MentionUnit from CatalogReader
 * @phpstan-import-type Detail from CatalogReader
 * @phpstan-import-type Report from MentionLoader
 * @phpstan-type Ref array{0: int, 1: int, 2: int, 3: ?string, 4: ?string, 5?: ?string, 6?: ?int}
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
        // One snapshot for every read, so that a load committed meanwhile cannot mix two releases. A caller's own
        // transaction must already give one (repeatable read or serializable).
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        } elseif (!$this->inOneSnapshot()) {
            throw new \LogicException('An index is read in one snapshot: the caller\'s transaction must be repeatable read or serializable.');
        }
        try {
            $refs  = $this->refsByEntry($edition, $type);
            $index = ['release' => $this->release(), 'entries' => $refs === [] ? [] : $this->entries($type, $refs)];
        } catch (\Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
        if ($own) {
            $this->db->commit();
        }
        return $index;
    }

    /** Whether the open transaction reads one snapshot throughout (repeatable read or serializable). */
    private function inOneSnapshot(): bool
    {
        $stmt = $this->db->query("SELECT current_setting('transaction_isolation')");
        return $stmt !== false && in_array($stmt->fetchColumn(), ['repeatable read', 'serializable'], true);
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
        $printed = $this->printed($edition);
        return array_map(static fn (array $list): array => array_map(
            static fn (array $r): array => isset($printed[self::key($r)]) ? [$r[0], $r[1], $r[2], $r[3], $r[4], $printed[self::key($r)][0], $printed[self::key($r)][1]] : $r,
            self::oneEstherText($list)
        ), $refs);
    }

    /**
     * The verses an edition prints otherwise than by their number (migrations 044/045): a label of their own ("E:21",
     * "12:1") or another chapter's heading, keyed as self::key. Few rows; none where the table lacks the columns.
     *
     * @return array<string, array{0: ?string, 1: ?int}>
     */
    private function printed(string $edition): array
    {
        if (preg_match('/^[A-Z0-9_]+$/', $edition) !== 1) {
            return [];
        }
        // Checked first, not caught: inside the index's transaction a failed query would abort the reads after it.
        $columns = $this->db->prepare(
            "SELECT count(*) FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name IN ('verse_label', 'printed_chapter')"
        );
        $columns->execute([$edition]);
        if ((int) $columns->fetchColumn() !== 2) {
            return [];
        }
        $stmt = $this->db->query('SELECT * FROM "' . $edition . '" WHERE verse_label IS NOT NULL OR printed_chapter IS NOT NULL');
        $out  = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $book = is_array($row) ? ( $row['book'] ?? null ) : null;
            if (!is_array($row) || !is_int($book)) {
                continue;
            }
            $f         = Placement::verseFields(array_filter($row, is_string(...), ARRAY_FILTER_USE_KEY));
            $key       = self::key([$book, $f['chapter'], $f['verse'], $f['verse_segment'], $f['text_tradition']]);
            $out[$key] = [$f['verse_label'], $f['printed_chapter']];
        }
        return $out;
    }

    /** @param Ref $r */
    private static function key(array $r): string
    {
        return $r[0] . '|' . $r[1] . '|' . $r[2] . '|' . ( $r[3] ?? '' ) . '|' . ( $r[4] ?? '' );
    }

    /**
     * An entry's verses with each Esther verse once: editions with both texts link a verse of each to the same
     * unit, and NVBSE's Hebrew run only repeats its Greek one, so the Hebrew ref goes where a Greek one has the
     * same verse. Verses left from both texts are put in chapter and verse order.
     *
     * @param list<Ref> $refs
     * @return list<Ref>
     */
    private static function oneEstherText(array $refs): array
    {
        $greek = [];
        foreach ($refs as $r) {
            if ($r[4] === 'GREEK') {
                $greek[] = [$r[0], $r[1], $r[2], $r[3]];
            }
        }
        if ($greek === []) {
            return $refs;
        }
        $kept = array_values(array_filter($refs, static fn (array $r): bool => $r[4] !== 'HEBREW' || !in_array([$r[0], $r[1], $r[2], $r[3]], $greek, true)));
        if (!in_array('HEBREW', array_column($kept, 4), true)) {
            return $kept;
        }
        // Verses of both texts: the edition's order runs one text after the other, so Esther's verses (one stretch,
        // as the refs go by book) go by chapter and verse; a stable sort keeps the edition's order inside a verse.
        $esther = array_keys(array_filter($kept, static fn (array $r): bool => $r[4] !== null));
        $slice  = array_slice($kept, $esther[0], count($esther));
        usort($slice, static fn (array $a, array $b): int => [$a[1], $a[2]] <=> [$b[1], $b[2]]);
        array_splice($kept, $esther[0], count($esther), $slice);
        return $kept;
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

    /**
     * A btu_link ref of a book as a Ref: [book, chapter, verse, segment, tradition]; null when it is not one.
     *
     * @return ?Ref
     */
    public static function ref(int $book, string $ref): ?array
    {
        if (preg_match(self::REF, $ref, $m) !== 1) {
            return null;
        }
        return [$book, (int) $m[1], (int) $m[2], $m[3] === '' ? null : $m[3], ( $m[4] ?? '' ) === '' ? null : $m[4]];
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
     * Replaces every entry in one transaction, with the editions' mentions (MentionLoader) and the entries' details,
     * and records the release loaded. Attested units CBTUDR does not know (no btu_unit row) are stored all the same,
     * and reported; an entry's mentioned units (settled mentions no source cites) follow its attested ones, flagged
     * `mentioned`. Details of an entry not loaded are left out, and reported. Once that transaction commits, the
     * number of verses of each person and place in every edition with mentions is counted from its index (which reads
     * its own snapshot, so it cannot run inside the load) and stored in bpe_verse_count, in a second transaction.
     *
     * @param list<Entry>                                $entries
     * @param array<string, array<string, MentionUnit>>  $mentions by edition, then unit key
     * @param array<string, Detail>                      $details  by entry id
     * @return array{entries: int, attested: int, mentioned: int, labels: int, unknown_units: list<string>, mentions: array<string, Report>, details: int, unknown_details: list<string>, verse_counts: int}
     */
    public function replaceAll(array $entries, string $commit, array $mentions = [], array $details = []): array
    {
        $this->db->beginTransaction();
        try {
            // bpe_mention first and by name: a mention under review has no entry, so no cascade reaches it.
            $this->db->exec('DELETE FROM bpe_mention');
            $this->db->exec('DELETE FROM bpe_verse_count');
            $this->db->exec('DELETE FROM bpe_detail');
            $this->db->exec('DELETE FROM bpe_entry'); // cascades to bpe_attested and bpe_label, the rest is emptied above
            $counts       = $this->insertEntries($entries);
            $unknown      = $this->db->query(
                'SELECT DISTINCT a.unit FROM bpe_attested a WHERE NOT EXISTS (SELECT 1 FROM btu_unit u WHERE u.id = a.unit) ORDER BY 1'
            );
            $unknownUnits = $unknown === false ? [] : array_values(array_filter($unknown->fetchAll(\PDO::FETCH_COLUMN), 'is_string'));
            $reports      = [];
            foreach ($mentions as $edition => $units) {
                $reports[$edition] = MentionLoader::load($this->db, $edition, $units);
            }
            [$loaded, $unknownDetails] = $this->insertDetails($details, array_column($entries, 'id'));
            $this->db->prepare('INSERT INTO bpe_release (commit, counts) VALUES (?, ?::jsonb)')->execute([
                $commit, self::json($counts + [
                    'unknown_units' => count($unknownUnits),
                    'mentions'      => array_sum(array_column($reports, 'loaded')),
                    'details'       => $loaded,
                ])
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $counts + [
            'unknown_units'   => $unknownUnits,
            'mentions'        => $reports,
            'details'         => $loaded,
            'unknown_details' => $unknownDetails,
            'verse_counts'    => $this->countVerses(array_keys($mentions)),
        ];
    }

    /**
     * @param list<Entry> $entries
     * @return array{entries: int, attested: int, mentioned: int, labels: int}
     */
    private function insertEntries(array $entries): array
    {
        $counts   = ['entries' => 0, 'attested' => 0, 'mentioned' => 0, 'labels' => 0];
        $entry    = $this->db->prepare(
            'INSERT INTO bpe_entry (id, type, kind, names, description, qid, qid_status, uncertain) VALUES (?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?)'
        );
        $attested = $this->db->prepare('INSERT INTO bpe_attested (entry, unit, ord, mentioned) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING');
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
            // A unit both attested and mentioned stays attested: the conflict keeps the first row.
            $ord = 0;
            foreach ([[$e['attested'], 'false', 'attested'], [$e['mentioned'] ?? [], 'true', 'mentioned']] as [$list, $flag, $count]) {
                foreach ($list as $unit) {
                    $attested->execute([$e['id'], $unit, $ord++, $flag]);
                    $counts[$count] += $attested->rowCount();
                }
            }
            foreach ($e['labels'] as $unit => $editions) {
                $label->execute([$e['id'], $unit, self::json($editions)]);
                $counts['labels']++;
            }
        }
        return $counts;
    }

    /**
     * @param array<string, Detail> $details
     * @param list<string>          $ids     the entries loaded
     * @return array{0: int, 1: list<string>} the details loaded, and the ids of those left out
     */
    private function insertDetails(array $details, array $ids): array
    {
        $known   = array_fill_keys($ids, true);
        $loaded  = 0;
        $unknown = [];
        $detail  = $this->db->prepare('INSERT INTO bpe_detail (entry, image, wikipedia, coords) VALUES (?, ?::jsonb, ?::jsonb, ?::jsonb)');
        foreach ($details as $id => $d) {
            if (!isset($known[$id])) {
                $unknown[] = $id;
                continue;
            }
            $detail->execute([
                $id,
                $d['image'] === null ? null : self::json((object) $d['image']),
                $d['wikipedia'] === null ? null : self::json((object) $d['wikipedia']),
                $d['coords'] === null ? null : self::json($d['coords']),
            ]);
            $loaded++;
        }
        return [$loaded, $unknown];
    }

    /**
     * Stores, for each edition, how many of its verses each person and place has in its index.
     *
     * @param list<string> $editions
     * @return int the rows stored
     */
    private function countVerses(array $editions): int
    {
        $stored = 0;
        foreach ($editions as $edition) {
            $counts = [];
            foreach (['person', 'place'] as $type) {
                foreach ($this->index($edition, $type)['entries'] as $e) {
                    $counts[$e['id']] = count($e['refs']);
                }
            }
            $this->db->beginTransaction();
            try {
                $this->db->prepare('DELETE FROM bpe_verse_count WHERE edition = ?')->execute([$edition]);
                $insert = $this->db->prepare('INSERT INTO bpe_verse_count (edition, entry, verses) VALUES (?, ?, ?)');
                foreach ($counts as $id => $verses) {
                    $insert->execute([$edition, $id, $verses]);
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $stored += count($counts);
        }
        return $stored;
    }

    private static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
