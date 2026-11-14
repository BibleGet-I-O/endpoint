<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Util\StringUtils;
use Psr\Log\LoggerInterface;

/**
 * Semantic (embedding) retrieval: embed a free-text query via the embedding
 * microservice, then run pgvector cosine similarity per version.
 *
 * Shared by `/v3/search/semantic` (SemanticSearchHandler) and the semantic
 * leg of `/v3/search/hybrid` (HybridSearchHandler).
 */
class SemanticSearchService
{
    public const UNAVAILABLE_MESSAGE = 'Semantic search is temporarily unavailable. Use /v3/search/keyword for text-based search.';

    public function __construct(private \PDO $pdo, private LoggerInterface $logger)
    {
    }

    /**
     * Embed $query once (reused across versions), and warn when the stored
     * embeddings of any requested version were computed with a different
     * model than the one the service reports.
     *
     * @param list<string> $versions Already-validated version sigla.
     * @param string $column pgvector column (one of SearchUtils::EMBEDDING_MODELS columns).
     * @return float[]
     * @throws ServiceUnavailableException with UNAVAILABLE_MESSAGE when the service is down.
     */
    public function embedQuery(EmbeddingClient $client, string $query, string $model, array $versions, string $column): array
    {
        $embedStart = microtime(true);
        try {
            $queryVector = $client->embed($query);
        } catch (ServiceUnavailableException $e) {
            $this->logger->warning('Embedding service unavailable: ' . $e->getMessage());
            throw new ServiceUnavailableException(self::UNAVAILABLE_MESSAGE);
        }
        $embedMs = round(( microtime(true) - $embedStart ) * 1000, 1);
        $this->logger->info('Embedding latency: ' . $embedMs . 'ms for query: ' . substr($query, 0, 100) . ' (model=' . $model . ')');

        $serviceModel = $client->getLastModel();
        if ($serviceModel !== '') {
            foreach ($versions as $v) {
                EmbeddingModelValidator::validate($this->pdo, $v, $serviceModel, $this->logger, $column);
            }
        }

        return $queryVector;
    }

    /**
     * Top-$limit verses of $version by cosine similarity to $queryVector,
     * ordered by similarity DESC, keeping only rows with similarity >= $threshold.
     *
     * @param float[] $queryVector
     * @param array{abbreviations: list<string>, books: list<string>, book_num: list<string>} $versionIndex
     * @param string $column pgvector column to query (one of SearchUtils::EMBEDDING_MODELS values).
     * @return array<int, array<string, mixed>>
     */
    public function executeSimilaritySearch(string $version, array $queryVector, array $versionIndex, int $limit, float $threshold, string $column): array
    {
        // Column name comes from SearchUtils::EMBEDDING_MODELS via modelColumn(),
        // a closed allowlist — but assert format here too so a future map entry
        // can't smuggle SQL through this concatenation.
        if (!preg_match('/^[a-z_]+$/', $column)) {
            throw new InternalServerErrorException('Invalid embedding column identifier.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $version)) {
            throw new InternalServerErrorException('Invalid version identifier.');
        }

        $vectorStr = '[' . implode(',', $queryVector) . ']';

        $sql = 'SELECT *, 1 - (' . $column . ' <=> ?::vector) AS score '
             . 'FROM "' . $version . '" '
             . 'WHERE ' . $column . ' IS NOT NULL '
             . 'AND 1 - (' . $column . ' <=> ?::vector) >= ? '
             . 'ORDER BY ' . $column . ' <=> ?::vector '
             . 'LIMIT ?';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$vectorStr, $vectorStr, $threshold, $vectorStr, $limit]);

        $results = [];
        while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $bookidx = array_search($row['book'], $versionIndex['book_num']);

            // null (rather than 0.0) when the score can't be computed, so a
            // genuine score of 0.0 — orthogonal verses — is distinguishable
            // from "no score". Comparators that order results must treat
            // null as worse than any numeric score.
            $score = isset($row['score']) && is_numeric($row['score']) && is_finite((float) $row['score'])
                ? round((float) $row['score'], 4)
                : null;

            $entry     = [
                'version'     => $version,
                'testament'   => is_numeric($row['testament']) ? (int) $row['testament'] : 0,
                'text'        => StringUtils::asString($row['text'] ?? ''),
                'text_plain'  => StringUtils::asString($row['text_plain'] ?? ''),
                'bookabbrev'  => $bookidx !== false ? ( $versionIndex['abbreviations'][$bookidx] ?? '' ) : '',
                'booknum'     => $bookidx !== false ? (int) $bookidx : 0,
                'univbooknum' => $row['book'],
                'book'        => $bookidx !== false ? ( $versionIndex['books'][$bookidx] ?? '' ) : '',
                'section'     => is_numeric($row['section']) ? (int) $row['section'] : 0,
                'chapter'     => is_numeric($row['chapter']) ? (int) $row['chapter'] : 0,
                'verse'       => is_numeric($row['verse']) ? (int) $row['verse'] : 0,
                'score'       => $score,
                // Carried forward solely so SearchUtils::assignCanonicalOrder
                // can rank rows; unset before the row leaves the handler.
                'verseID'     => is_numeric($row['verseID'] ?? null) ? (int) $row['verseID'] : 0,
            ];
            $results[] = $entry;
        }

        return $results;
    }
}
