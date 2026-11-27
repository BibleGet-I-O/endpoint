<?php

declare(strict_types=1);

namespace BibleGet\Api\Cbppedr;

/**
 * Reads a CBPPEDR checkout's three catalogs (data/persons.json, places.json, events.json) into the rows
 * EntityRepository::replaceAll loads. data/unnamed.json is not read: its persons have no catalog id yet.
 *
 * @phpstan-type Entry array{id: string, type: string, kind: string, names: array<string, string>, description: array<string, string>, qid: ?string, qid_status: ?string, uncertain: bool, attested: list<string>, labels: array<string, list<string>>}
 */
final class CatalogReader
{
    private const array CATALOGS = ['persons', 'places', 'events'];

    /**
     * @return array{entries: list<Entry>}
     */
    public function read(string $dir): array
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
        return ['entries' => $entries];
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
            'attested'    => array_values(array_filter(is_array($e['attested'] ?? null) ? $e['attested'] : [], 'is_string')),
            'labels'      => $labels,
        ];
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
