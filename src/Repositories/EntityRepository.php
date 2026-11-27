<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

use BibleGet\Api\Cbppedr\CatalogReader;

/**
 * The CBPPEDR catalogs as loaded (migration 072): persons, places and events, the CBTUDR units where each is
 * attested, and for a labelled unit the editions that name the entry there.
 *
 * @phpstan-import-type Entry from CatalogReader
 */
final class EntityRepository
{
    private const string TEXT_ARRAY = 'ARRAY(SELECT json_array_elements_text(?::json))';

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
