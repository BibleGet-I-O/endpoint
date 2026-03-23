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

    private string $baseUrl;

    /** Consecutive failure count (persists across requests in CLI server / PHP-FPM). */
    private static int $failures = 0;

    /** Timestamp when the circuit was tripped open (0 = closed). */
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
        } catch (ServiceUnavailableException $e) {
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
        } catch (ServiceUnavailableException $e) {
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
        if (self::$openSince === 0.0) {
            return; // Circuit is closed
        }

        $elapsed = microtime(true) - self::$openSince;
        if ($elapsed < self::COOLDOWN_SECONDS) {
            throw new ServiceUnavailableException(
                'Embedding service circuit breaker is open (cooldown ' . (int) ( self::COOLDOWN_SECONDS - $elapsed ) . 's remaining). '
                . 'Use /v3/search/keyword for text-based search.'
            );
        }

        // Cooldown elapsed — transition to half-open (allow one probe request)
        // Reset openSince so only one request goes through; if it fails, recordFailure re-trips
        self::$openSince = 0.0;
    }

    private function recordFailure(): void
    {
        self::$failures++;
        if (self::$failures >= self::FAILURE_THRESHOLD) {
            self::$openSince = microtime(true);
        }
    }

    private function recordSuccess(): void
    {
        self::$failures  = 0;
        self::$openSince = 0.0;
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

        curl_close($ch);

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
