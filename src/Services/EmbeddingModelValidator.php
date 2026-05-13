<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use Psr\Log\LoggerInterface;

/**
 * Validates that stored embeddings are current: same model as the embedding
 * service, and no verse text has changed since embeddings were last computed.
 */
class EmbeddingModelValidator
{
    /**
     * Check that the embedding model used for the given (version, column) pair
     * matches the model reported by the embedding service, and that verse
     * content has not changed since embeddings were computed. Logs warnings on
     * mismatches but does not throw — results may be degraded but are still
     * usable.
     *
     * Migration 012 widened the embedding_metadata primary key to
     * (version_sigla, column_name) so that the `embedding` (MiniLM) and
     * `embedding_labse` (LaBSE) columns can each carry their own metadata
     * row per version. Callers must pass the column name they actually
     * queried so the right row is checked.
     *
     * @param string $serviceModel Model name from the embedding service response
     * @param string $columnName   pgvector column queried (e.g. `embedding`,
     *                             `embedding_labse`); selects which metadata
     *                             row is validated.
     */
    public static function validate(
        \PDO $pdo,
        string $version,
        string $serviceModel,
        LoggerInterface $logger,
        string $columnName = 'embedding'
    ): void {
        $stmt = $pdo->prepare(
            'SELECT model_name, content_xor FROM embedding_metadata '
            . 'WHERE version_sigla = ? AND column_name = ?'
        );
        $stmt->execute([$version, $columnName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $logger->warning(
                'No embedding metadata found for version ' . $version
                . ' (column ' . $columnName . '). '
                . 'Embeddings may not have been computed yet.'
            );
            return;
        }

        $storedModel = $row['model_name'] ?? '';
        if (is_string($storedModel) && $storedModel !== '' && $storedModel !== $serviceModel) {
            $logger->warning(
                'Embedding model mismatch for version ' . $version
                . ' (column ' . $columnName . '): '
                . 'stored embeddings use "' . $storedModel . '", '
                . 'but the embedding service uses "' . $serviceModel . '". '
                . 'Results may be degraded. Re-run compute_embeddings.py to reindex.'
            );
        }

        /** @var array<string, mixed> $row */
        self::checkContentStaleness($pdo, $version, $row, $logger);
    }

    /**
     * Compare the stored content_xor fingerprint against the current XOR of
     * all text_hash values. A mismatch means verse text has changed since
     * embeddings were last computed.
     *
     * @param array<string, mixed> $metadataRow
     */
    private static function checkContentStaleness(
        \PDO $pdo,
        string $version,
        array $metadataRow,
        LoggerInterface $logger
    ): void {
        $storedXor = $metadataRow['content_xor'] ?? null;
        if ($storedXor === null) {
            // No fingerprint stored yet — migration 005 may not have run,
            // or embeddings were computed before the feature was added.
            return;
        }

        // Check if the version table has text_hash (migration 005)
        if (!preg_match('/^[A-Za-z0-9_]+$/', $version)) {
            return;
        }
        try {
            $result = $pdo->query(
                'SELECT bytea_xor_agg(text_hash) FROM "' . $version . '"'
            );
        } catch (\PDOException) {
            // bytea_xor_agg or text_hash column doesn't exist
            return;
        }
        if ($result === false) {
            return;
        }

        $row = $result->fetch(\PDO::FETCH_NUM);
        if (!is_array($row)) {
            return;
        }
        $currentXor = $row[0] ?? null;
        if ($currentXor === null) {
            return;
        }

        if ($currentXor !== $storedXor) {
            $logger->warning(
                'Verse content has changed for version ' . $version
                . ' since embeddings were last computed. '
                . 'Some search results may be stale. '
                . 'Re-run compute_embeddings.py to update.'
            );
        }
    }
}
