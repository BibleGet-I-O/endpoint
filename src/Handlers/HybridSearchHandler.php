<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Services\EmbeddingClient;
use BibleGet\Api\Services\KeywordSearchService;
use BibleGet\Api\Services\SemanticSearchService;
use BibleGet\Api\Util\SearchUtils;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * `/v3/search/hybrid` — fuses the keyword (`match=fulltext`) and semantic
 * retrievers with weighted Reciprocal Rank Fusion (Cormack et al. 2009):
 *
 *     RRF(d) = Σ_i  w_i / (k + rank_i(d))
 *
 * where rank_i(d) is d's 1-based rank in leg i's candidate list (the term is
 * omitted when d was not retrieved by leg i). Each leg retrieves
 * `candidates = min(MAX_CANDIDATES, max(limit * 3, MIN_CANDIDATES))` rows
 * (merged globally across versions, like the sibling endpoints), the lists are
 * fused, and the top `limit` fused rows are returned.
 *
 * A leg whose weight is 0 is not executed. When the query is too short for the
 * keyword leg's 4-character minimum, only the semantic leg runs.
 */
class HybridSearchHandler extends AbstractHandler
{
    private const ENDPOINT_VERSION  = '3.0';
    private const DEFAULT_LIMIT     = 20;
    private const MAX_LIMIT         = 100;
    private const DEFAULT_THRESHOLD = 0.0;
    private const DEFAULT_K         = 60;
    private const DEFAULT_WEIGHT    = 1.0;

    /** Per-leg over-fetch factor relative to `limit`. */
    private const CANDIDATE_FACTOR = 3;
    /** Floor on per-leg candidates, so small limits still fuse over a meaningful pool. */
    private const MIN_CANDIDATES = 50;
    /** Ceiling on per-leg candidates: CANDIDATE_FACTOR × MAX_LIMIT. */
    private const MAX_CANDIDATES = self::CANDIDATE_FACTOR * self::MAX_LIMIT;

    private LoggerInterface $logger;
    private ?EmbeddingClient $embeddingClient;

    /**
     * @param string[] $requestPathParams
     * @param EmbeddingClient|null $embeddingClient When non-null, used verbatim
     *        for the semantic leg (tests); the `model=` param then only selects
     *        the pgvector column. When null (production), a model-bound client
     *        is constructed per request from the validated `model=` slug.
     */
    public function __construct(ResponseFactoryInterface $responseFactory, array $requestPathParams = [], ?EmbeddingClient $embeddingClient = null)
    {
        parent::__construct($responseFactory, $requestPathParams);
        $this->embeddingClient = $embeddingClient;
    }

    /**
     * Reset cached data (for testing only).
     */
    public static function resetCache(): void
    {
        KeywordSearchService::resetCache();
    }

    /**
     * Per-leg retrieval depth for a given final `limit`.
     */
    public static function candidatesFor(int $limit): int
    {
        return min(self::MAX_CANDIDATES, max($limit * self::CANDIDATE_FACTOR, self::MIN_CANDIDATES));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Nyholm\Psr7\Response(200, [], null, $request->getProtocolVersion(), 'OK');
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);
        $this->validateRequestContentType($request);
        $this->logger = LoggerFactory::create('api');

        $params      = $this->getRequestParams($request);
        $contentType = $this->resolveResponseContentType($request, $params);
        $response    = $this->initResponse($request, $contentType);

        [$query, $versions, $limit, $threshold, $model, $k, $keywordWeight, $semanticWeight] = $this->extractParams($params);

        $column     = SearchUtils::modelColumn($model);
        $candidates = self::candidatesFor($limit);

        $pdo             = Connection::getConnection();
        $keywordService  = new KeywordSearchService($pdo, $this->logger);
        $semanticService = new SemanticSearchService($pdo, $this->logger);

        // The keyword leg's constraints (known versions, single ts_language)
        // are a superset of the semantic leg's, so they validate the request.
        [$validatedVersions, $tsLanguage] = $keywordService->validateVersions($versions, '/search/hybrid');

        $queryLongEnough = KeywordSearchService::isFulltextKeywordLongEnough($query);
        $runKeyword      = $keywordWeight > 0 && $queryLongEnough;
        $runSemantic     = $semanticWeight > 0;
        if (!$runKeyword && !$runSemantic) {
            // Only reachable with semantic_weight=0 and a too-short query.
            throw new ValidationException(
                'Query must be at least ' . KeywordSearchService::MIN_KEYWORD_LENGTH
                . ' characters long for the keyword leg of hybrid search when semantic_weight is 0.'
            );
        }

        $versionIndexes = [];
        foreach ($validatedVersions as $v) {
            $versionIndexes[$v] = SearchUtils::loadVersionIndex($pdo, $v, $this->logger);
        }

        $keywordRows = [];
        if ($runKeyword) {
            foreach ($validatedVersions as $v) {
                $stmt = $keywordService->executeSearch($v, $query, 'fulltext', $tsLanguage, $candidates);
                array_push($keywordRows, ...$keywordService->mapSearchResults($stmt, $v, $versionIndexes[$v]));
            }
            SearchUtils::sortByScoreDesc($keywordRows);
            $keywordRows = array_slice($keywordRows, 0, $candidates);
        }

        $semanticRows = [];
        if ($runSemantic) {
            $client      = $this->embeddingClient ?? new EmbeddingClient($model);
            $queryVector = $semanticService->embedQuery($client, $query, $model, $validatedVersions, $column);
            foreach ($validatedVersions as $v) {
                array_push($semanticRows, ...$semanticService->executeSimilaritySearch(
                    $v,
                    $queryVector,
                    $versionIndexes[$v],
                    $candidates,
                    $threshold,
                    $column
                ));
            }
            SearchUtils::sortByScoreDesc($semanticRows);
            $semanticRows = array_slice($semanticRows, 0, $candidates);
        }

        [$results, $components] = $this->fuse(
            ['keyword' => $keywordRows, 'semantic' => $semanticRows],
            ['keyword' => $keywordWeight, 'semantic' => $semanticWeight],
            $k,
            array_flip($validatedVersions),
            $limit
        );

        // Per-version 1-based canonical_order (subverse-aware), verseID stripped.
        SearchUtils::assignCanonicalOrder($results);
        foreach ($results as $i => &$row) {
            $row['components'] = $components[$i];
        }
        unset($row);

        $legs = [];
        if ($runKeyword) {
            $legs[] = 'keyword';
        }
        if ($runSemantic) {
            $legs[] = 'semantic';
        }
        $this->logger->info(
            'Hybrid search: legs=' . implode(',', $legs) . ' keyword=' . count($keywordRows)
            . ' semantic=' . count($semanticRows) . ' fused=' . count($results)
        );

        $body          = new \stdClass();
        $body->results = $results;
        $body->errors  = [];
        $body->info    = [
            'ENDPOINT_VERSION' => self::ENDPOINT_VERSION,
            'model'            => $model,
            'k'                => $k,
            'keyword_weight'   => $keywordWeight,
            'semantic_weight'  => $semanticWeight,
            'candidates'       => $candidates,
            'legs'             => $legs,
        ];

        $response = $this->buildResponse($response, $contentType, $body, $results);
        return $this->withCacheHeaders($request, $response);
    }

    /**
     * Weighted Reciprocal Rank Fusion over the legs' rank-ordered lists.
     *
     * Rows are identified by (version, verseID). Ties on the fused score are
     * broken by the better (lower) best-rank across legs, then by the
     * requested version order, then by verseID (canonical order).
     *
     * @param array<string, array<int, array<string, mixed>>> $legs Rank-ordered rows per leg name.
     * @param array<string, float> $weights Weight per leg name.
     * @param array<string, int> $versionOrder Requested position of each version.
     * @return array{array<int, array<string, mixed>>, array<int, array<string, array{rank: int, score: float|null}|null>>}
     *         [top-$limit rows with `score` = fused RRF sum (verseID still present), components per row]
     */
    private function fuse(array $legs, array $weights, int $k, array $versionOrder, int $limit): array
    {
        /** @var array<string, array{row: array<string, mixed>, fused: float, bestRank: int, components: array<string, array{rank: int, score: float|null}|null>}> $fused */
        $fused = [];
        foreach ($legs as $leg => $rows) {
            $weight = $weights[$leg] ?? 0.0;
            $rank   = 0;
            foreach ($rows as $row) {
                $rank++;
                $key = ( is_string($row['version'] ?? null) ? $row['version'] : '' )
                    . ':' . ( is_int($row['verseID'] ?? null) ? $row['verseID'] : 0 );
                if (!isset($fused[$key])) {
                    $fused[$key] = [
                        'row'        => $row,
                        'fused'      => 0.0,
                        'bestRank'   => PHP_INT_MAX,
                        'components' => array_fill_keys(array_keys($legs), null),
                    ];
                }
                $legScore                        = $row['score'] ?? null;
                $fused[$key]['fused']           += $weight / ( (float) $k + $rank );
                $fused[$key]['bestRank']         = min($fused[$key]['bestRank'], $rank);
                $fused[$key]['components'][$leg] = [
                    'rank'  => $rank,
                    'score' => is_float($legScore) ? $legScore : null,
                ];
            }
        }

        $entries = array_values($fused);
        usort($entries, static function (array $a, array $b) use ($versionOrder): int {
            $cmp = $b['fused'] <=> $a['fused'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $a['bestRank'] <=> $b['bestRank'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $va  = is_string($a['row']['version'] ?? null) ? $a['row']['version'] : '';
            $vb  = is_string($b['row']['version'] ?? null) ? $b['row']['version'] : '';
            $cmp = ( $versionOrder[$va] ?? 0 ) <=> ( $versionOrder[$vb] ?? 0 );
            if ($cmp !== 0) {
                return $cmp;
            }
            return ( $a['row']['verseID'] ?? 0 ) <=> ( $b['row']['verseID'] ?? 0 );
        });
        $entries = array_slice($entries, 0, $limit);

        $results    = [];
        $components = [];
        foreach ($entries as $entry) {
            $row          = $entry['row'];
            $row['score'] = $entry['fused'];
            $results[]    = $row;
            $components[] = $entry['components'];
        }
        return [$results, $components];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, list<string>, int, float, string, int, float, float}
     */
    private function extractParams(array $params): array
    {
        $queryRaw = $params['query'] ?? '';
        $query    = is_string($queryRaw) ? trim($queryRaw) : '';
        if ($query === '') {
            throw new ValidationException('The query parameter is required.');
        }

        // Comma-separated `version=A,B,C` (single value still accepted).
        $versions = SearchUtils::parseVersionsParam($params['version'] ?? '');

        $limit     = SearchUtils::parseLimitParam($params['limit'] ?? null, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $threshold = SearchUtils::parseThresholdParam($params['threshold'] ?? null, self::DEFAULT_THRESHOLD);
        $model     = SearchUtils::parseModelParam($params['model'] ?? null);
        $k         = $this->parseK($params['k'] ?? null);

        $keywordWeight  = $this->parseWeight($params['keyword_weight'] ?? null, 'keyword_weight');
        $semanticWeight = $this->parseWeight($params['semantic_weight'] ?? null, 'semantic_weight');
        if ($keywordWeight === 0.0 && $semanticWeight === 0.0) {
            throw new ValidationException('keyword_weight and semantic_weight cannot both be 0.');
        }

        return [$query, $versions, $limit, $threshold, $model, $k, $keywordWeight, $semanticWeight];
    }

    private function parseK(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_K;
        }
        $k = ( is_string($raw) || is_int($raw) )
            ? filter_var(is_string($raw) ? trim($raw) : $raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if (!is_int($k)) {
            throw new ValidationException('Invalid k: must be a positive integer.');
        }
        return $k;
    }

    private function parseWeight(mixed $raw, string $name): float
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_WEIGHT;
        }
        $weight = ( is_string($raw) || is_int($raw) || is_float($raw) )
            ? filter_var(is_string($raw) ? trim($raw) : $raw, FILTER_VALIDATE_FLOAT)
            : false;
        if (!is_float($weight) || !is_finite($weight) || $weight < 0) {
            throw new ValidationException('Invalid ' . $name . ': must be a non-negative finite number.');
        }
        // Normalise -0.0 so the "both zero" check and the info block are clean.
        return $weight == 0 ? 0.0 : $weight;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function buildResponse(ResponseInterface $response, string $contentType, \stdClass $body, array $results): ResponseInterface
    {
        if ($contentType === 'application/json') {
            return $this->jsonResponse($response, $body);
        }

        if ($contentType === 'application/xml') {
            $root = '<?xml version="1.0" encoding="UTF-8"?><BibleGetHybridSearch/>';
            $xml  = new \SimpleXMLElement($root);
            $xml->addChild('errors');
            $xmlInfo    = $xml->addChild('info');
            $xmlResults = $xml->addChild('results');
            $xmlInfo->addAttribute('ENDPOINT_VERSION', self::ENDPOINT_VERSION);

            foreach ($results as $row) {
                $resultNode = $xmlResults->addChild('result');
                foreach ($row as $key => $value) {
                    if ($key === 'components') {
                        continue;
                    }
                    $resultNode[$key] = is_scalar($value) ? (string) $value : '';
                }
                // Per-leg components as child elements; a leg that did not
                // retrieve the row is simply absent.
                $componentsNode = $resultNode->addChild('components');
                $components     = is_array($row['components'] ?? null) ? $row['components'] : [];
                foreach ($components as $leg => $component) {
                    if (!is_array($component)) {
                        continue;
                    }
                    $legNode = $componentsNode->addChild((string) $leg);
                    $legNode->addAttribute('rank', is_scalar($component['rank'] ?? null) ? (string) $component['rank'] : '');
                    $legNode->addAttribute('score', is_scalar($component['score'] ?? null) ? (string) $component['score'] : '');
                }
            }

            $xmlString = $xml->asXML();
            return $this->xmlResponse($response, $xmlString !== false ? $xmlString : '');
        }

        // HTML
        $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $this->htmlResponse($response, '<pre>' . htmlspecialchars($json !== false ? $json : '{}') . '</pre>');
    }
}
