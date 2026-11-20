<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

/**
 * The review store of CBTUDR unit links (migration 053): the registry's units, each edition's links from its
 * verses to them, and the stretches of drafted links. A link is `rule`, `draft` or `reviewed`; only a reviewer's
 * action makes it `reviewed`.
 *
 * A link a reviewer has touched (`reviewed_by` set: accepted, relinked, marked without counterpart, flagged for a
 * split, or re-kinded) is never overwritten or removed by loading new drafts, even by a load that runs while the
 * reviewer works: the upsert itself skips it. A link the registry has *reviewed* (a review exported to CBTUDR, or
 * a split made there) replaces any link here that is not itself reviewed, a touched draft included, and clears its
 * note; a link reviewed here is kept, being the newer decision. Its stretch is frozen when it is first touched (a copy with the id
 * `r<link id>`), because each drafting run numbers its stretches anew.
 *
 * A chapter view and "accept the chapter" take every text tradition of the chapter together (Esther's Greek and
 * Hebrew runs, `10:3@GREEK` and `10:3@HEBREW`).
 *
 * Arrays of unit ids travel as JSON (array_to_json / json_array_elements_text), never as hand-built literals.
 *
 * @phpstan-type Link array{id: int, ref: string, units: list<string>, kind: string, status: string, confidence: ?float, stretch: ?string, note: ?string, reviewed_by: ?string}
 * @phpstan-type LoadLink array{ref: string, ord: int, units: list<string>, kind: string, status: string, confidence?: ?float, stretch?: ?string}
 */
class BtuRepository
{
    private const LINK_COLUMNS = 'id, ref, array_to_json(units) AS units, kind, status, confidence, stretch, note, reviewed_by';
    /** The chapter in a link's ref, "5:3@GREEK" -> 5. */
    private const REF_CHAPTER = "split_part(split_part(ref, '@', 1), ':', 1)::int";
    private const JSON_ARRAY  = 'ARRAY(SELECT json_array_elements_text(?::json))';

    public function __construct(private readonly \PDO $db)
    {
    }

    /**
     * @param list<array{id: string, book: int, ord: int, parent?: ?string, after?: ?string, nv_omitted?: bool}> $units
     */
    public function upsertUnits(array $units): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO btu_unit (id, book, ord, parent, after, nv_omitted) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET book = EXCLUDED.book, ord = EXCLUDED.ord, parent = EXCLUDED.parent,
                 after = EXCLUDED.after, nv_omitted = EXCLUDED.nv_omitted'
        );
        foreach ($units as $u) {
            $stmt->execute([$u['id'], $u['book'], $u['ord'], $u['parent'] ?? null, $u['after'] ?? null, ( $u['nv_omitted'] ?? false ) ? 'true' : 'false']);
        }
        return count($units);
    }

    /**
     * One edition's links for one book, and the book's stretches. A reviewed link is kept as it is; other
     * links are inserted or updated, and those whose ref is no longer drafted are removed.
     *
     * @param list<LoadLink> $links
     * @param list<array{id: string, units: list<string>}> $stretches
     * @return array{inserted: int, updated: int, kept: int}
     */
    public function loadEdition(string $sigla, int $book, array $links, array $stretches): array
    {
        $this->db->beginTransaction();
        try {
            $existing = $this->db->prepare('SELECT ref, status FROM btu_link WHERE edition = ? AND book = ?');
            $existing->execute([$sigla, $book]);
            $status = [];
            foreach ($existing->fetchAll() as $row) {
                $r = Cast::row($row);
                if ($r !== null) {
                    $status[Cast::string($r['ref'])] = Cast::string($r['status']);
                }
            }
            // Touched links first get their own stretch, so the stretches loaded below cannot clash with theirs.
            $this->freeze('l.edition = ? AND l.book = ? AND l.reviewed_by IS NOT NULL', [$sigla, $book]);
            $result = $this->writeLinks($sigla, $book, $links, $status);
            $keep   = array_map(static fn (array $l) => $l['ref'], $links);
            $del    = $this->db->prepare('DELETE FROM btu_link WHERE edition = ? AND book = ? AND reviewed_by IS NULL AND NOT (ref = ANY(' . self::JSON_ARRAY . '))');
            $del->execute([$sigla, $book, json_encode($keep)]);
            // Every stretch but the frozen copies that touched links still use.
            $this->db->prepare(
                'DELETE FROM btu_stretch s WHERE s.edition = ? AND s.book = ? AND NOT EXISTS
                   (SELECT 1 FROM btu_link l WHERE l.edition = s.edition AND l.book = s.book AND l.stretch = s.id AND l.reviewed_by IS NOT NULL)'
            )->execute([$sigla, $book]);
            $ins = $this->db->prepare('INSERT INTO btu_stretch (edition, book, id, units) VALUES (?, ?, ?, ' . self::JSON_ARRAY . ')');
            foreach ($stretches as $s) {
                $ins->execute([$sigla, $book, $s['id'], json_encode($s['units'])]);
            }
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param list<LoadLink> $links
     * @param array<string, string> $status the current status of each ref
     * @return array{inserted: int, updated: int, kept: int}
     */
    private function writeLinks(string $sigla, int $book, array $links, array $status): array
    {
        $upsert = $this->db->prepare(
            'INSERT INTO btu_link (edition, book, ord, ref, units, kind, status, confidence, stretch)
             VALUES (?, ?, ?, ?, ' . self::JSON_ARRAY . ', ?, ?, ?, ?)
             ON CONFLICT (edition, book, ref) DO UPDATE SET ord = EXCLUDED.ord, units = EXCLUDED.units, kind = EXCLUDED.kind,
                 status = EXCLUDED.status, confidence = EXCLUDED.confidence,
                 stretch = CASE WHEN EXCLUDED.status = \'reviewed\' THEN NULL ELSE EXCLUDED.stretch END,
                 note = CASE WHEN EXCLUDED.status = \'reviewed\' THEN NULL ELSE btu_link.note END
             WHERE btu_link.reviewed_by IS NULL OR (EXCLUDED.status = \'reviewed\' AND btu_link.status <> \'reviewed\')'
        );
        $count  = ['inserted' => 0, 'updated' => 0, 'kept' => 0];
        foreach ($links as $l) {
            $was = $status[$l['ref']] ?? null;
            $upsert->execute([
                $sigla,
                $book,
                $l['ord'],
                $l['ref'],
                json_encode($l['units']),
                $l['kind'],
                $l['status'],
                $l['confidence'] ?? null,
                $l['stretch'] ?? null,
            ]);
            // No row written: a reviewer touched the link (now or while this load ran).
            $count[$upsert->rowCount() === 0 ? 'kept' : ( $was === null ? 'inserted' : 'updated' )]++;
        }
        return $count;
    }

    /**
     * @return list<array{edition: string, draft: int, reviewed: int, rule: int}>
     */
    public function editions(): array
    {
        $stmt = $this->db->query(
            "SELECT edition, count(*) FILTER (WHERE status = 'draft') AS draft, count(*) FILTER (WHERE status = 'reviewed') AS reviewed,
                    count(*) FILTER (WHERE status = 'rule') AS rule
             FROM btu_link GROUP BY edition ORDER BY edition"
        );
        $out  = [];
        foreach ($stmt !== false ? $stmt->fetchAll() : [] as $row) {
            $r = Cast::row($row);
            if ($r !== null) {
                $out[] = ['edition' => Cast::string($r['edition']), 'draft' => Cast::int($r['draft']), 'reviewed' => Cast::int($r['reviewed']), 'rule' => Cast::int($r['rule'])];
            }
        }
        return $out;
    }

    /**
     * The chapters of an edition that still have drafts, the least confident first.
     *
     * @return list<array{book: int, chapter: int, drafts: int, min_confidence: float}>
     */
    public function chaptersWithDrafts(string $sigla): array
    {
        $stmt = $this->db->prepare(
            'SELECT book, ' . self::REF_CHAPTER . " AS chapter, count(*) AS drafts, min(coalesce(confidence, 0)) AS min_confidence
             FROM btu_link WHERE edition = ? AND status = 'draft' GROUP BY 1, 2 ORDER BY 4, 1, 2"
        );
        $stmt->execute([$sigla]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $r = Cast::row($row);
            if ($r !== null) {
                $out[] = ['book' => Cast::int($r['book']), 'chapter' => Cast::int($r['chapter']), 'drafts' => Cast::int($r['drafts']), 'min_confidence' => (float) Cast::string($r['min_confidence'])];
            }
        }
        return $out;
    }

    /**
     * The chapters of an edition that have links of a status, in book and chapter order (for the export of
     * reviewed links back to CBTUDR).
     *
     * @return list<array{book: int, chapter: int, links: int}>
     */
    public function chaptersWithStatus(string $sigla, string $status): array
    {
        $stmt = $this->db->prepare(
            'SELECT book, ' . self::REF_CHAPTER . ' AS chapter, count(*) AS links FROM btu_link WHERE edition = ? AND status = ? GROUP BY 1, 2 ORDER BY 1, 2'
        );
        $stmt->execute([$sigla, $status]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $r = Cast::row($row);
            if ($r !== null) {
                $out[] = ['book' => Cast::int($r['book']), 'chapter' => Cast::int($r['chapter']), 'links' => Cast::int($r['links'])];
            }
        }
        return $out;
    }

    /**
     * A chapter's links, in the edition's order, and the units of the stretches they belong to.
     *
     * @return array{links: list<Link>, units: list<array{id: string, ref: string, stretch: string}>}
     */
    public function chapter(string $sigla, int $book, int $chapter): array
    {
        $stmt = $this->db->prepare('SELECT ' . self::LINK_COLUMNS . ' FROM btu_link WHERE edition = ? AND book = ? AND ' . self::REF_CHAPTER . ' = ? ORDER BY ord');
        $stmt->execute([$sigla, $book, $chapter]);
        $links     = array_values(array_filter(array_map(self::shape(...), $stmt->fetchAll())));
        $stretches = array_values(array_unique(array_filter(array_column($links, 'stretch'))));
        $units     = [];
        foreach ($stretches as $sid) {
            foreach ($this->stretchUnits($sigla, $book, $sid) as $uid) {
                $units[] = ['id' => $uid, 'ref' => self::unitRef($uid), 'stretch' => $sid];
            }
        }
        return ['links' => $links, 'units' => $units];
    }

    /**
     * The units a draft of this stretch may be relinked to.
     *
     * @return list<string>
     */
    public function stretchUnits(string $sigla, int $book, string $stretch): array
    {
        $stmt = $this->db->prepare('SELECT array_to_json(units) FROM btu_stretch WHERE edition = ? AND book = ? AND id = ?');
        $stmt->execute([$sigla, $book, $stretch]);
        $json = $stmt->fetchColumn();
        return is_string($json) ? self::stringList($json) : [];
    }

    /** @return ?array{edition: string, book: int, link: Link} */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT edition, book, ' . self::LINK_COLUMNS . ' FROM btu_link WHERE id = ?');
        $stmt->execute([$id]);
        $row  = Cast::row($stmt->fetch());
        $link = $row !== null ? self::shape($row) : null;
        return $row !== null && $link !== null ? ['edition' => Cast::string($row['edition']), 'book' => Cast::int($row['book']), 'link' => $link] : null;
    }

    /** @return ?Link */
    public function link(int $id): ?array
    {
        return $this->find($id)['link'] ?? null;
    }

    /**
     * Applies one reviewer action, already validated by the caller:
     * accept; relink (to `units`); none (no counterpart); split (a `note`; the link stays a draft); or `kind`.
     *
     * Null when the link is gone or is a rule link (a reload may have changed it since it was read).
     *
     * @param array{action?: string, units?: list<string>, note?: string, kind?: string} $change
     * @return ?Link
     */
    public function review(int $id, array $change, string $sub): ?array
    {
        $set = match ($change['action'] ?? 'kind') {
            'accept' => ["status = 'reviewed'", []],
            'relink' => ["status = 'reviewed', units = " . self::JSON_ARRAY, [json_encode($change['units'] ?? [])]],
            'none'   => ["status = 'reviewed', units = '{}'", []],
            'split'  => ['note = ?', [$change['note'] ?? '']],
            default  => ['kind = ?', [$change['kind'] ?? 'same']],
        };
        $this->db->beginTransaction();
        try {
            $this->freezeStretch($id);
            $stmt = $this->db->prepare('UPDATE btu_link SET ' . $set[0] . ", reviewed_by = ?, reviewed_at = now() WHERE id = ? AND status <> 'rule'");
            $stmt->execute([...$set[1], $sub, $id]);
            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return null;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->link($id);
    }

    /** Points a link about to be touched at its own copy of its stretch (`r<id>`), which reloads keep. */
    private function freezeStretch(int $id): void
    {
        $this->freeze('l.id = ?', [$id]);
    }

    /**
     * Gives the links matching `$where` (on `btu_link l`) their own copy of their stretch, `r<id>`, which reloads
     * keep: drafting runs number stretches anew, so a reviewed link must not share a stretch id with them.
     *
     * @param list<int|string> $params
     */
    private function freeze(string $where, array $params): void
    {
        $this->db->prepare(
            "INSERT INTO btu_stretch (edition, book, id, units)
             SELECT l.edition, l.book, 'r' || l.id, s.units FROM btu_link l
             JOIN btu_stretch s ON s.edition = l.edition AND s.book = l.book AND s.id = l.stretch
             WHERE ($where) AND l.stretch NOT LIKE 'r%' ON CONFLICT DO NOTHING"
        )->execute($params);
        $this->db->prepare(
            "UPDATE btu_link AS l SET stretch = 'r' || l.id WHERE ($where) AND l.stretch IS NOT NULL AND l.stretch NOT LIKE 'r%'
               AND EXISTS (SELECT 1 FROM btu_stretch s WHERE s.edition = l.edition AND s.book = l.book AND s.id = 'r' || l.id)"
        )->execute($params);
    }

    /** Accepts the chapter's remaining drafts, except those flagged for a split; returns how many. */
    public function acceptChapter(string $sigla, int $book, int $chapter, string $sub): int
    {
        $drafts = "l.edition = ? AND l.book = ? AND l.status = 'draft' AND l.note IS NULL AND "
            . str_replace('ref', 'l.ref', self::REF_CHAPTER) . ' = ?';
        $this->db->beginTransaction();
        try {
            $this->freeze($drafts, [$sigla, $book, $chapter]);
            $stmt = $this->db->prepare("UPDATE btu_link AS l SET status = 'reviewed', reviewed_by = ?, reviewed_at = now() WHERE " . $drafts);
            $stmt->execute([$sub, $sigla, $book, $chapter]);
            $this->db->commit();
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** "btu:tb.5.4.2" -> "5:4" (the Nova Vulgata reference a unit began as). */
    public static function unitRef(string $id): string
    {
        $parts = explode('.', (string) preg_replace('/^btu:[a-z0-9]+\./', '', $id));
        return ( $parts[0] ?? '' ) . ':' . preg_replace('/\+.*/', '', $parts[1] ?? '');
    }

    /** @return list<string> */
    private static function stringList(string $json): array
    {
        $v = json_decode($json, true);
        return is_array($v) ? array_values(array_map(static fn ($x) => Cast::string($x), $v)) : [];
    }

    /** @return ?Link */
    private static function shape(mixed $row): ?array
    {
        $r = Cast::row($row);
        if ($r === null) {
            return null;
        }
        return [
            'id'          => Cast::int($r['id']),
            'ref'         => Cast::string($r['ref']),
            'units'       => self::stringList(Cast::string($r['units'])),
            'kind'        => Cast::string($r['kind']),
            'status'      => Cast::string($r['status']),
            'confidence'  => $r['confidence'] === null ? null : (float) Cast::string($r['confidence']),
            'stretch'     => $r['stretch'] === null ? null : Cast::string($r['stretch']),
            'note'        => $r['note'] === null ? null : Cast::string($r['note']),
            'reviewed_by' => $r['reviewed_by'] === null ? null : Cast::string($r['reviewed_by']),
        ];
    }
}
