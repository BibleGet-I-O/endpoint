<?php

declare(strict_types=1);

namespace BibleGet\Api\Cbppedr;

/**
 * Reads a CBPPEDR checkout's three catalogs (data/persons.json, places.json, events.json) into the rows
 * EntityRepository::replaceAll loads. data/unnamed.json is not read: its persons have no catalog id yet.
 *
 * Also read: where the entries are named in an edition's text (data/mentions/<edition>.json, generated, and
 * <edition>_curated.json, hand corrections that replace the generated file unit by unit; a curated unit with no
 * mentions removes it), and what a card shows of an entry (data/details.json, absent until CBPPEDR builds it). A
 * mention's positions are UTF-16 code units of the row's text_plain, and its check the first 8 hex digits of the
 * SHA-256 of the span's text; MentionLoader verifies both. An entry's `mentioned` units are those of its settled
 * mentions that no source cites.
 *
 * @phpstan-type Entry array{id: string, type: string, kind: string, names: array<string, string>, description: array<string, string>, qid: ?string, qid_status: ?string, uncertain: bool, attested: list<string>, labels: array<string, list<string>>, mentioned?: list<string>}
 * @phpstan-type Mention array{start: int, end: int, check: string, entry: ?string, candidates: ?list<string>}
 * @phpstan-type MentionUnit array{hash: string, mentions: list<Mention>}
 * @phpstan-type Detail array{image: ?array<string, string>, wikipedia: ?array<string, string>, coords: ?array{lat: float, lon: float}}
 */
final class CatalogReader
{
    private const array CATALOGS = ['persons', 'places', 'events'];

    /**
     * @return array{entries: list<Entry>, mentions: array<string, array<string, MentionUnit>>, details: array<string, Detail>}
     */
    public function read(string $dir): array
    {
        return ['entries' => self::entries($dir), 'mentions' => self::mentions($dir), 'details' => self::details($dir)];
    }

    /** @return list<Entry> */
    private static function entries(string $dir): array
    {
        $entries = [];
        foreach (self::CATALOGS as $catalog) {
            $path = $dir . '/data/' . $catalog . '.json';
            if (!is_file($path)) {
                throw new \RuntimeException("No CBPPEDR catalog at $path");
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data) || !is_array($data['entries'] ?? null)) {
                throw new \RuntimeException("Not a CBPPEDR catalog: $path");
            }
            foreach ($data['entries'] as $id => $e) {
                if (is_string($id) && is_array($e)) {
                    $entries[] = self::entry($id, $e);
                }
            }
        }
        return $entries;
    }

    /**
     * Each edition's units with mentions, by unit key, the curated file over the generated one.
     *
     * @return array<string, array<string, MentionUnit>>
     */
    private static function mentions(string $dir): array
    {
        $files = glob($dir . '/data/mentions/*.json') ?: [];
        // Generated files first, so that the curated ones apply over them.
        usort($files, static fn (string $a, string $b): int => [str_ends_with($a, '_curated.json'), $a] <=> [str_ends_with($b, '_curated.json'), $b]);
        /** @var array<string, array<string, MentionUnit>> $out */
        $out = [];
        foreach ($files as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data) || !is_string($data['edition'] ?? null) || !is_array($data['units'] ?? null)) {
                throw new \RuntimeException("Not a CBPPEDR mentions file: $path");
            }
            $units = self::applyUnits($out[$data['edition']] ?? [], $data['units']);
            if ($units === []) {
                unset($out[$data['edition']]);
            } else {
                $out[$data['edition']] = $units;
            }
        }
        return $out;
    }

    /**
     * A file's units over those read before: each replaces the one with its key, and one with no mentions removes it.
     *
     * @param array<string, MentionUnit> $units
     * @param array<mixed>               $file
     * @return array<string, MentionUnit>
     */
    private static function applyUnits(array $units, array $file): array
    {
        foreach ($file as $key => $unit) {
            if (!is_string($key) || !is_array($unit) || !is_string($unit['hash'] ?? null)) {
                continue;
            }
            $mentions = array_values(array_filter(array_map(self::mention(...), is_array($unit['mentions'] ?? null) ? $unit['mentions'] : [])));
            if ($mentions === []) {
                unset($units[$key]);
                continue;
            }
            $units[$key] = ['hash' => strtolower($unit['hash']), 'mentions' => $mentions];
        }
        return $units;
    }

    /** @return ?Mention */
    private static function mention(mixed $m): ?array
    {
        if (!is_array($m) || !is_int($m['start'] ?? null) || !is_int($m['end'] ?? null) || !is_string($m['check'] ?? null)) {
            return null;
        }
        $entry      = is_string($m['entry'] ?? null) ? $m['entry'] : null;
        $candidates = is_array($m['candidates'] ?? null) ? array_values(array_filter($m['candidates'], 'is_string')) : null;
        if (( $entry === null ) === ( $candidates === null || $candidates === [] )) {
            return null; // neither, or both
        }
        return ['start' => $m['start'], 'end' => $m['end'], 'check' => strtolower($m['check']), 'entry' => $entry, 'candidates' => $entry === null ? $candidates : null];
    }

    /** @return array<string, Detail> */
    private static function details(string $dir): array
    {
        $path = $dir . '/data/details.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !is_array($data['entries'] ?? null)) {
            throw new \RuntimeException("Not a CBPPEDR details file: $path");
        }
        $out = [];
        foreach ($data['entries'] as $id => $d) {
            if (!is_string($id) || !is_array($d)) {
                continue;
            }
            $image     = self::strings($d['image'] ?? null);
            $wikipedia = self::strings($d['wikipedia'] ?? null);
            $coords    = $d['coords'] ?? null;
            $out[$id]  = [
                'image'     => $image === [] ? null : $image,
                'wikipedia' => $wikipedia === [] ? null : $wikipedia,
                'coords'    => is_array($coords) && is_numeric($coords['lat'] ?? null) && is_numeric($coords['lon'] ?? null)
                    ? ['lat' => (float) $coords['lat'], 'lon' => (float) $coords['lon']]
                    : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<mixed> $e
     * @return Entry
     */
    private static function entry(string $id, array $e): array
    {
        $labels = [];
        foreach (is_array($e['labels'] ?? null) ? $e['labels'] : [] as $unit => $label) {
            if (is_string($unit) && is_array($label) && is_array($label['editions'] ?? null)) {
                $labels[$unit] = array_values(array_filter(array_keys($label['editions']), 'is_string'));
            }
        }
        return [
            'id'          => $id,
            'type'        => is_string($e['type'] ?? null) ? $e['type'] : '',
            'kind'        => is_string($e['kind'] ?? null) ? $e['kind'] : '',
            'names'       => self::strings($e['names'] ?? null),
            'description' => self::strings($e['description'] ?? null),
            'qid'         => is_string($e['qid'] ?? null) ? $e['qid'] : null,
            'qid_status'  => is_string($e['qid_status'] ?? null) ? $e['qid_status'] : null,
            'uncertain'   => ( $e['review'] ?? null ) === 'uncertain',
            'attested'    => self::list($e['attested'] ?? null),
            'labels'      => $labels,
            'mentioned'   => self::list($e['mentioned'] ?? null),
        ];
    }

    /** @return list<string> */
    private static function list(mixed $list): array
    {
        return array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
    }

    /** @return array<string, string> */
    private static function strings(mixed $map): array
    {
        $out = [];
        foreach (is_array($map) ? $map : [] as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
