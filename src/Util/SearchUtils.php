<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ValidationException;
use Psr\Log\LoggerInterface;

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
     * Parse a `limit=` parameter, clamping to [1, $max]. Non-numeric values
     * fall back to $default rather than erroring (the convention shared by
     * `/search/semantic`, `/search/similar` and `/search/hybrid`).
     */
    public static function parseLimitParam(mixed $raw, int $default, int $max): int
    {
        $limit = is_numeric($raw) ? (int) $raw : $default;
        return max(1, min($limit, $max));
    }

    /**
     * Parse a cosine-similarity `threshold=` parameter, clamping to [0, 1].
     * Non-numeric values fall back to $default.
     */
    public static function parseThresholdParam(mixed $raw, float $default = 0.0): float
    {
        $threshold = is_numeric($raw) ? (float) $raw : $default;
        return max(0.0, min($threshold, 1.0));
    }

    /**
     * Sort rows by `score` DESC in place. Null scores (computation failures)
     * sort to the bottom so a genuine 0.0 still outranks them. usort is
     * stable, so rows with equal scores keep their incoming order.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function sortByScoreDesc(array &$rows): void
    {
        usort($rows, static function (array $a, array $b): int {
            $sa = $a['score'] ?? null;
            $sb = $b['score'] ?? null;
            if ($sa === null && $sb === null) {
                return 0;
            }
            if ($sa === null) {
                return 1;
            }
            if ($sb === null) {
                return -1;
            }
            return $sb <=> $sa;
        });
    }

    /**
     * Load a version's `*_idx` table: per-book abbreviation, full name and
     * universal book number, in index order (the index position is the
     * version-local `booknum` emitted on every search row).
     *
     * @return array{abbreviations: list<string>, books: list<string>, book_num: list<string>}
     * @throws ValidationException when the version identifier is malformed
     * @throws InternalServerErrorException on database failure or an empty index
     */
    public static function loadVersionIndex(\PDO $pdo, string $version, LoggerInterface $logger): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $version)) {
            throw new ValidationException('Invalid version identifier format: ' . $version);
        }
        $abbreviations = $books = $book_num = [];
        try {
            $idxResult = $pdo->query('SELECT * FROM "' . $version . '_idx"');
        } catch (\PDOException $e) {
            $logger->error('Failed to load index for version ' . $version . ': ' . $e->getMessage());
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        if ($idxResult === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        while (is_array($row = $idxResult->fetch(\PDO::FETCH_ASSOC))) {
            $abbreviations[] = StringUtils::asString($row['abbrev'] ?? '');
            $books[]         = StringUtils::asString($row['fullname'] ?? '');
            $book_num[]      = StringUtils::asString($row['book'] ?? '');
        }
        if (empty($abbreviations)) {
            throw new InternalServerErrorException('No index data found for version: ' . $version);
        }
        return ['abbreviations' => $abbreviations, 'books' => $books, 'book_num' => $book_num];
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
