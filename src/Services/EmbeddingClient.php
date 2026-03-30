<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;

/**
 * Client for the Python embedding microservice.
 *
 * Calls the FastAPI service to vectorize text for pgvector similarity search.
 * Includes a circuit breaker that trips open after repeated failures and
 * short-circuits to a 503 for a cooldown period, avoiding unnecessary load.
 */
class EmbeddingClient
{
    private const FAILURE_THRESHOLD = 5;
    private const COOLDOWN_SECONDS  = 30;

    private const APCU_KEY_FAILURES   = 'bibleget:embedding_cb:failures';
    private const APCU_KEY_OPEN_SINCE = 'bibleget:embedding_cb:open_since';

    private string $baseUrl;

    /** Fallback failure count when APCu is unavailable. */
    private static int $failures = 0;

    /** Fallback open-since timestamp when APCu is unavailable. */
    private static float $openSince = 0.0;

    /** Model name from the last successful embed() response. */
    private string $lastModel = '';

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl ?? self::resolveBaseUrl();
    }

    /**
     * Reset the circuit breaker state (for testing only).
     */
    public static function resetCircuitBreaker(): void
    {
        self::$failures  = 0;
        self::$openSince = 0.0;
        if (self::hasApcu()) {
            apcu_delete(self::APCU_KEY_FAILURES);
            apcu_delete(self::APCU_KEY_OPEN_SINCE);
        }
    }

    private static function hasApcu(): bool
    {
        return function_exists('apcu_store') && apcu_enabled();
    }

    private static function getFailures(): int
    {
        if (self::hasApcu()) {
            $val = apcu_fetch(self::APCU_KEY_FAILURES);
            return is_int($val) ? $val : 0;
        }
        return self::$failures;
    }

    private static function setFailures(int $count): void
    {
        if (self::hasApcu()) {
            apcu_store(self::APCU_KEY_FAILURES, $count, self::COOLDOWN_SECONDS * 2);
        }
        self::$failures = $count;
    }

    private static function getOpenSince(): float
    {
        if (self::hasApcu()) {
            $val = apcu_fetch(self::APCU_KEY_OPEN_SINCE);
            return is_float($val) ? $val : 0.0;
        }
        return self::$openSince;
    }

    private static function setOpenSince(float $timestamp): void
    {
        if (self::hasApcu()) {
            if ($timestamp === 0.0) {
                apcu_delete(self::APCU_KEY_OPEN_SINCE);
            } else {
                apcu_store(self::APCU_KEY_OPEN_SINCE, $timestamp, self::COOLDOWN_SECONDS * 2);
            }
        }
        self::$openSince = $timestamp;
    }

    /**
     * Embed a single text string into a 384-dimensional vector.
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
        $openSince = self::getOpenSince();
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
        self::setOpenSince(0.0);
    }

    private function recordFailure(): void
    {
        $failures = self::getFailures() + 1;
        self::setFailures($failures);
        if ($failures >= self::FAILURE_THRESHOLD) {
            self::setOpenSince(microtime(true));
        }
    }

    private function recordSuccess(): void
    {
        self::setFailures(0);
        self::setOpenSince(0.0);
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
            $error = curl_error($ch);
            curl_close($ch);
            throw new ServiceUnavailableException('Embedding service unavailable: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode === 503) {
            throw new ServiceUnavailableException(
                'Embedding service temporarily unavailable (HTTP 503)'
            );
        }

        if ($statusCode !== 200) {
            throw new InternalServerErrorException(
                'Embedding service returned unexpected HTTP ' . $statusCode
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

        $ch = curl_init($url);
        if ($ch === false) {
            throw new InternalServerErrorException('Failed to initialize cURL for embedding service.');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ServiceUnavailableException('Embedding service unavailable: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode === 503) {
            throw new ServiceUnavailableException(
                'Embedding service temporarily unavailable (HTTP 503)'
            );
        }

        if ($statusCode !== 200) {
            throw new InternalServerErrorException(
                'Embedding service returned unexpected HTTP ' . $statusCode
            );
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new InternalServerErrorException('Embedding service returned invalid JSON.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function resolveBaseUrl(): string
    {
        foreach (['$_ENV', '$_SERVER', 'getenv'] as $source) {
            if ($source === 'getenv') {
                $val = getenv('EMBEDDING_SERVICE_URL');
                if ($val !== false && $val !== '') {
                    return $val;
                }
            } elseif ($source === '$_ENV') {
                if (isset($_ENV['EMBEDDING_SERVICE_URL']) && is_string($_ENV['EMBEDDING_SERVICE_URL']) && $_ENV['EMBEDDING_SERVICE_URL'] !== '') {
                    return $_ENV['EMBEDDING_SERVICE_URL'];
                }
            } else {
                if (isset($_SERVER['EMBEDDING_SERVICE_URL']) && is_string($_SERVER['EMBEDDING_SERVICE_URL']) && $_SERVER['EMBEDDING_SERVICE_URL'] !== '') {
                    return $_SERVER['EMBEDDING_SERVICE_URL'];
                }
            }
        }

        // Default for local development
        return 'http://localhost:8001';
    }
}
