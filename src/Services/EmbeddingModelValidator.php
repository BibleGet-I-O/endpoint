<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Http\Exception\InternalServerErrorException;
use Psr\Log\LoggerInterface;

/**
 * Validates that stored embeddings were computed with the same model
 * as the one currently used by the embedding service.
 */
class EmbeddingModelValidator
{
    /**
     * Check that the embedding model used for the given version matches the
     * model reported by the embedding service. Logs a warning on mismatch
     * but does not throw — results may be degraded but are still usable.
     *
     * @param string $serviceModel Model name from the embedding service response
     */
    public static function validate(
        \PDO $pdo,
        string $version,
        string $serviceModel,
        LoggerInterface $logger
    ): void {
        $stmt = $pdo->prepare(
            'SELECT model_name FROM embedding_metadata WHERE version_sigla = ?'
        );
        $stmt->execute([$version]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $logger->warning(
                'No embedding metadata found for version ' . $version
                . '. Embeddings may not have been computed yet.'
            );
            return;
        }

        $storedModel = $row['model_name'] ?? '';
        if (!is_string($storedModel) || $storedModel === '') {
            return;
        }

        if ($storedModel !== $serviceModel) {
            $logger->warning(
                'Embedding model mismatch for version ' . $version . ': '
                . 'stored embeddings use "' . $storedModel . '", '
                . 'but the embedding service uses "' . $serviceModel . '". '
                . 'Results may be degraded. Re-run compute_embeddings.py to reindex.'
            );
        }
    }
}
