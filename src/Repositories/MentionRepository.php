<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

/**
 * CBPPEDR's mentions in the editions' text and what a card shows of an entry (migrations 072/073), for
 * /v3/quote?mentions=1. A mention's positions are code points of its row's formatted text, valid only while the row's
 * text_plain still hashes to plain_hash. Images, Wikipedia titles and coordinates are given only for an entry whose
 * Wikidata item has been reviewed or adjudicated.
 *
 * @phpstan-type MentionRow array{chapter: int, verse: int, segment: ?string, tradition: ?string, plain_hash: string, start: int, end: int, entry: ?string, candidates: ?list<string>}
 * @phpstan-type Card array{type: string, kind: string, names: \stdClass, description: \stdClass, uncertain: bool, verse_count: int, image?: \stdClass, wikipedia?: \stdClass, coords?: array{lat: float, lon: float}}
 */
class MentionRepository
{
    public function __construct(private readonly \PDO $db)
    {
    }

    /**
     * Runs $read in one read-only, repeatable-read transaction, so that a load committed meanwhile cannot mix two
     * catalogs (mentions of one, cards of the other); inside the caller's transaction when one is open.
     *
     * @template T
     * @param callable(): T $read
     * @return T
     */
    public function inOneSnapshot(callable $read): mixed
    {
        if ($this->db->inTransaction()) {
            return $read();
        }
        $this->db->beginTransaction();
        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $result = $read();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->db->commit();
        return $result;
    }

    /**
     * Those of $editions that have any mention.
     *
     * @param list<string> $editions
     * @return list<string>
     */
    public function editionsWithMentions(array $editions): array
    {
        if ($editions === []) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT DISTINCT edition FROM bpe_mention WHERE edition = ANY(?::text[])');
        $stmt->execute([self::textArray($editions)]);
        return array_values(array_filter($stmt->fetchAll(\PDO::FETCH_COLUMN), 'is_string'));
    }

    /**
     * Every mention of the edition in chapters $chapterFrom-$chapterTo of $book (universal number).
     *
     * @return list<MentionRow>
     */
    public function mentions(string $edition, int $book, int $chapterFrom, int $chapterTo): array
    {
        $stmt = $this->db->prepare(
            'SELECT chapter, verse, segment, tradition, plain_hash, start_cp, end_cp, entry, array_to_json(candidates)::text AS candidates
               FROM bpe_mention WHERE edition = ? AND book = ? AND chapter BETWEEN ? AND ?'
        );
        $stmt->execute([$edition, $book, $chapterFrom, $chapterTo]);
        $out = [];
        foreach (Cast::rows($stmt->fetchAll()) as $r) {
            $out[] = [
                'chapter'    => Cast::int($r['chapter']),
                'verse'      => Cast::int($r['verse']),
                'segment'    => self::str($r['segment']),
                'tradition'  => self::str($r['tradition']),
                'plain_hash' => Cast::string($r['plain_hash']),
                'start'      => Cast::int($r['start_cp']),
                'end'        => Cast::int($r['end_cp']),
                'entry'      => self::str($r['entry']),
                'candidates' => self::candidates($r['candidates']),
            ];
        }
        return $out;
    }

    /**
     * The cards of the entries $ids, keyed by id, with the number of $edition's verses in each one's index entry.
     *
     * @param list<string> $ids
     * @return array<string, Card>
     */
    public function cards(string $edition, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT e.id, e.type, e.kind, e.names::text AS names, e.description::text AS description, e.uncertain,
                    COALESCE(v.verses, 0) AS verses, e.qid_status IN ('reviewed', 'adjudicated') AS shown,
                    d.image::text AS image, d.wikipedia::text AS wikipedia, d.coords::text AS coords
               FROM bpe_entry e
               LEFT JOIN bpe_detail d ON d.entry = e.id
               LEFT JOIN bpe_verse_count v ON v.entry = e.id AND v.edition = ?
              WHERE e.id = ANY(?::text[])
              ORDER BY e.id"
        );
        $stmt->execute([$edition, self::textArray($ids)]);
        $out = [];
        foreach (Cast::rows($stmt->fetchAll()) as $r) {
            $card = [
                'type'        => Cast::string($r['type']),
                'kind'        => Cast::string($r['kind']),
                'names'       => (object) self::stringMap($r['names']),
                'description' => (object) self::stringMap($r['description']),
                'uncertain'   => Cast::bool($r['uncertain']),
                'verse_count' => Cast::int($r['verses']),
            ];
            if (Cast::bool($r['shown'])) {
                $card += self::details($r);
            }
            $out[Cast::string($r['id'])] = $card;
        }
        return $out;
    }

    /**
     * An entry's image, Wikipedia titles and coordinates, each only when present.
     *
     * @param array<string, mixed> $r
     * @return array{image?: \stdClass, wikipedia?: \stdClass, coords?: array{lat: float, lon: float}}
     */
    private static function details(array $r): array
    {
        $out = [];
        foreach (['image', 'wikipedia'] as $k) {
            $map = self::stringMap($r[$k]);
            if ($map !== []) {
                $out[$k] = (object) $map;
            }
        }
        $coords = is_string($r['coords']) ? json_decode($r['coords'], true) : null;
        if (is_array($coords) && is_numeric($coords['lat'] ?? null) && is_numeric($coords['lon'] ?? null)) {
            $out['coords'] = ['lat' => (float) $coords['lat'], 'lon' => (float) $coords['lon']];
        }
        return $out;
    }

    /** @param list<string> $values */
    private static function textArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn (string $v): string => '"' . addcslashes($v, '"\\') . '"', $values)) . '}';
    }

    /** @return ?list<string> */
    private static function candidates(mixed $json): ?array
    {
        $v = is_string($json) ? json_decode($json, true) : null;
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : null;
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

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : Cast::string($v);
    }
}
