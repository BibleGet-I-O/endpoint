<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Util\SearchUtils;

/**
 * Client for the Python embedding microservice.
 *
 * Calls the FastAPI service to vectorize text for pgvector similarity search.
 * Includes a circuit breaker that trips open after repeated failures and
 * short-circuits to a 503 for a cooldown period, avoiding unnecessary load.
 *
 * Per `SearchUtils::EMBEDDING_MODELS`, the client is bound to a specific model
 * slug at construction (`labse` by default). Each model has its own FastAPI
 * service (different port, different sentence-transformers model) so the URL
 * is resolved from a per-model env var.
 */
class EmbeddingClient
{
    private const FAILURE_THRESHOLD = 5;
    private const COOLDOWN_SECONDS  = 30;

    // Per-model APCu key prefixes — the model slug is appended at use-time so
    // a failing LaBSE service can't trip the breaker for MiniLM (and vice
    // versa). Each model talks to a different FastAPI process on a different
    // port; their availability is independent.
    private const APCU_KEY_FAILURES_PREFIX   = 'bibleget:embedding_cb:failures:';
    private const APCU_KEY_OPEN_SINCE_PREFIX = 'bibleget:embedding_cb:open_since:';

    private string $baseUrl;
    private string $modelSlug;

    /**
     * Fallback failure counts when APCu is unavailable. Keyed by model slug
     * to keep per-model breaker state isolated.
     *
     * @var array<string, int>
     */
    private static array $failures = [];

    /**
     * Fallback open-since timestamps when APCu is unavailable, keyed by slug.
     *
     * @var array<string, float>
     */
    private static array $openSince = [];

    /** Model name from the last successful embed() response. */
    private string $lastModel = '';

    /**
     * @param string $modelSlug `labse` or `minilm` (see SearchUtils::EMBEDDING_MODELS).
     * @param string|null $baseUrl Override the resolved URL (testing only).
     */
    public function __construct(string $modelSlug = SearchUtils::DEFAULT_EMBEDDING_MODEL, ?string $baseUrl = null)
    {
        if (!isset(SearchUtils::EMBEDDING_MODELS[$modelSlug])) {
            throw new \InvalidArgumentException('Unknown embedding model slug: ' . $modelSlug);
        }
        $this->modelSlug = $modelSlug;
        $this->baseUrl   = $baseUrl ?? self::resolveBaseUrl($modelSlug);
    }

    /**
     * Public accessor for the model slug this client is bound to.
     * Used by handlers to look up the matching pgvector column / metadata row.
     */
    public function getModelSlug(): string
    {
        return $this->modelSlug;
    }

    /**
     * Reset circuit-breaker state for every model (for testing only). Clears
     * both APCu entries and the in-process fallback maps for all slugs
     * declared in `SearchUtils::EMBEDDING_MODELS` so a single call wipes
     * the breaker regardless of which models the test exercised.
     */
    public static function resetCircuitBreaker(): void
    {
        self::$failures  = [];
        self::$openSince = [];
        if (self::hasApcu()) {
            foreach (array_keys(SearchUtils::EMBEDDING_MODELS) as $slug) {
                apcu_delete(self::APCU_KEY_FAILURES_PREFIX . $slug);
                apcu_delete(self::APCU_KEY_OPEN_SINCE_PREFIX . $slug);
            }
        }
    }

    private static function hasApcu(): bool
    {
        return function_exists('apcu_store') && apcu_enabled();
    }

    private function apcuFailuresKey(): string
    {
        return self::APCU_KEY_FAILURES_PREFIX . $this->modelSlug;
    }

    private function apcuOpenSinceKey(): string
    {
        return self::APCU_KEY_OPEN_SINCE_PREFIX . $this->modelSlug;
    }

    private function getFailures(): int
    {
        if (self::hasApcu()) {
            $val = apcu_fetch($this->apcuFailuresKey());
            return is_int($val) ? $val : 0;
        }
        return self::$failures[$this->modelSlug] ?? 0;
    }

    private function setFailures(int $count): void
    {
        if (self::hasApcu()) {
            apcu_store($this->apcuFailuresKey(), $count, self::COOLDOWN_SECONDS * 2);
        }
        self::$failures[$this->modelSlug] = $count;
    }

    private function getOpenSince(): float
    {
        if (self::hasApcu()) {
            $val = apcu_fetch($this->apcuOpenSinceKey());
            return is_float($val) ? $val : 0.0;
        }
        return self::$openSince[$this->modelSlug] ?? 0.0;
    }

    private function setOpenSince(float $timestamp): void
    {
        if (self::hasApcu()) {
            if ($timestamp === 0.0) {
                apcu_delete($this->apcuOpenSinceKey());
            } else {
                apcu_store($this->apcuOpenSinceKey(), $timestamp, self::COOLDOWN_SECONDS * 2);
            }
        }
        self::$openSince[$this->modelSlug] = $timestamp;
    }

    /**
     * Embed a single text string into a vector. The returned array's length
     * depends on the model this client is bound to (`$this->modelSlug`):
     * 384 for `minilm`, 768 for `labse`.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        $this->checkCircuit();

        try {
            $response = $this->post('/embed', ['text' => $text]);
        } catch (ServiceUnavailableException | InternalServerErrorException $e) {
            $this->recordFailure();
            throw $e;
        }

        $this->recordSuccess();

        if (!isset($response['embedding']) || !is_array($response['embedding'])) {
            throw new InternalServerErrorException('Embedding service returned invalid response.');
        }

        if (isset($response['model']) && is_string($response['model'])) {
            $this->lastModel = $response['model'];
        }

        /** @var float[] */
        return $response['embedding'];
    }

    /**
     * Get the model name from the last successful embed() call.
     */
    public function getLastModel(): string
    {
        return $this->lastModel;
    }

    /**
     * Embed multiple texts in a single request.
     *
     * @param string[] $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array
    {
        $this->checkCircuit();

        try {
            $response = $this->post('/embed/batch', ['texts' => array_values($texts)]);
        } catch (ServiceUnavailableException | InternalServerErrorException $e) {
            $this->recordFailure();
            throw $e;
        }

        $this->recordSuccess();

        if (!isset($response['embeddings']) || !is_array($response['embeddings'])) {
            throw new InternalServerErrorException('Embedding service returned invalid response.');
        }

        /** @var float[][] */
        return $response['embeddings'];
    }

    /**
     * Check if the embedding service is reachable and healthy.
     */
    public function isHealthy(): bool
    {
        try {
            $response = $this->get('/health');
            return ( $response['status'] ?? '' ) === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check the circuit breaker state. Throws immediately if the circuit is open
     * and the cooldown has not elapsed. Allows a single probe request (half-open)
     * once the cooldown expires.
     */
    private function checkCircuit(): void
    {
        $openSince = $this->getOpenSince();
        if ($openSince === 0.0) {
            return; // Circuit is closed
        }

        $elapsed = microtime(true) - $openSince;
        if ($elapsed < self::COOLDOWN_SECONDS) {
            throw new ServiceUnavailableException(
                'Embedding service circuit breaker is open (cooldown ' . (int) ( self::COOLDOWN_SECONDS - $elapsed ) . 's remaining). '
                . 'Use /v3/search/keyword for text-based search.'
            );
        }

        // Cooldown elapsed — transition to half-open (allow one probe request)
        // Reset openSince so only one request goes through; if it fails, recordFailure re-trips
        $this->setOpenSince(0.0);
    }

    private function recordFailure(): void
    {
        $failures = $this->getFailures() + 1;
        $this->setFailures($failures);
        if ($failures >= self::FAILURE_THRESHOLD) {
            $this->setOpenSince(microtime(true));
        }
    }

    private function recordSuccess(): void
    {
        $this->setFailures(0);
        $this->setOpenSince(0.0);
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $path, mixed $body): array
    {
        $url     = rtrim($this->baseUrl, '/') . $path;
        $payload = json_encode($body);
        if ($payload === false) {
            throw new InternalServerErrorException('Failed to encode embedding request.');
        }

        // Since PHP 8.0 cURL handles are objects, freed automatically when $ch
        // falls out of scope; curl_close() is a no-op and deprecated as of PHP
        // 8.4 (slated for removal).
        $ch = curl_init($url);
        if ($ch === false) {
            throw new InternalServerErrorException('Failed to initialize cURL for embedding service.');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new ServiceUnavailableException('Embedding service unavailable: ' . curl_error($ch));
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($statusCode === 503) {
            throw new ServiceUnavailableException(
                'Embedding service temporarily unavailable (HTTP 503)'
            );
        }

        if ($statusCode !== 200) {
            throw new InternalServerErrorException(
                'Embedding service returned HTTP ' . $statusCode . ': ' . substr((string) $raw, 0, 200)
            );
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new InternalServerErrorException('Embedding service returned invalid JSON.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        // See post() for the rationale on dropping curl_close().
        $ch = curl_init($url);
        if ($ch === false) {
            throw new InternalServerErrorException('Failed to initialize cURL for embedding service.');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new ServiceUnavailableException('Embedding service unavailable: ' . curl_error($ch));
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($statusCode === 503) {
            throw new ServiceUnavailableException(
                'Embedding service temporarily unavailable (HTTP 503)'
            );
        }

        if ($statusCode !== 200) {
            throw new InternalServerErrorException(
                'Embedding service returned HTTP ' . $statusCode . ': ' . substr((string) $raw, 0, 200)
            );
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new InternalServerErrorException('Embedding service returned invalid JSON.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Resolve the FastAPI service URL for a given model slug.
     *
     * Lookup order:
     *   1. Per-model env var (`EMBEDDING_SERVICE_URL_LABSE` / `..._MINILM`)
     *   2. Legacy `EMBEDDING_SERVICE_URL` — only honoured for the `minilm`
     *      slug, since live deployments that predate the LaBSE split point
     *      that variable at the MiniLM service. Honouring it for `labse`
     *      would silently route LaBSE queries to MiniLM vectors.
     *   3. Per-model dev default (port 8000 for MiniLM, 8002 for LaBSE).
     */
    private static function resolveBaseUrl(string $modelSlug): string
    {
        $modelConfig = SearchUtils::EMBEDDING_MODELS[$modelSlug];
        $envName     = $modelConfig['service_env'];

        $primary = self::readEnv($envName);
        if ($primary !== null) {
            return $primary;
        }

        if ($modelSlug === 'minilm') {
            $legacy = self::readEnv('EMBEDDING_SERVICE_URL');
            if ($legacy !== null) {
                return $legacy;
            }
        }

        return $modelConfig['default_url'];
    }

    /**
     * Read an env var across the three PHP-SAPI surfaces, returning the first
     * non-empty value or null. Centralised so resolveBaseUrl reads each
     * variable consistently.
     */
    private static function readEnv(string $name): ?string
    {
        if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }
        if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }
        $val = getenv($name);
        if (is_string($val) && $val !== '') {
            return $val;
        }
        return null;
    }
}
