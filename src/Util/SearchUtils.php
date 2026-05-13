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
     * Per-model configuration for semantic / similar search. Each entry pairs
     * a public model slug (the value the client sends as `model=`) with the
     * pgvector column where vectors for that model are stored and the env-var
     * used to override its FastAPI service URL.
     *
     * LaBSE is the default because it outperformed MiniLM on Latin (NVBSE)
     * during the A/B experiment in discussion #107.
     */
    public const EMBEDDING_MODELS = [
        'labse'  => [
            'column'      => 'embedding_labse',
            'service_env' => 'EMBEDDING_SERVICE_URL_LABSE',
            'default_url' => 'http://127.0.0.1:8002',
        ],
        'minilm' => [
            'column'      => 'embedding',
            'service_env' => 'EMBEDDING_SERVICE_URL_MINILM',
            'default_url' => 'http://127.0.0.1:8000',
        ],
    ];

    public const DEFAULT_EMBEDDING_MODEL = 'labse';

    /**
     * Parse a `model=` parameter into one of the supported slugs
     * (`labse`, `minilm`). Case-insensitive. Empty / missing → default.
     *
     * @throws ValidationException when the value is not a recognised slug.
     */
    public static function parseModelParam(mixed $raw, string $default = self::DEFAULT_EMBEDDING_MODEL): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }
        $slug = strtolower(trim($raw));
        if (!isset(self::EMBEDDING_MODELS[$slug])) {
            $valid = implode(', ', array_keys(self::EMBEDDING_MODELS));
            throw new ValidationException('Invalid model: ' . $raw . '. Valid values: ' . $valid);
        }
        return $slug;
    }

    /**
     * Resolve the pgvector column name for a given model slug. The slug must
     * have been validated already (e.g. via `parseModelParam`); this method
     * raises in that case to make misuse obvious.
     */
    public static function modelColumn(string $modelSlug): string
    {
        if (!isset(self::EMBEDDING_MODELS[$modelSlug])) {
            throw new \InvalidArgumentException('Unknown embedding model slug: ' . $modelSlug);
        }
        return self::EMBEDDING_MODELS[$modelSlug]['column'];
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
