<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Http\Exception\InternalServerErrorException;

/**
 * Client for the Python embedding microservice.
 *
 * Calls the FastAPI service to vectorize text for pgvector similarity search.
 */
class EmbeddingClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl ?? self::resolveBaseUrl();
    }

    /**
     * Embed a single text string into a 384-dimensional vector.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        $response = $this->post('/embed', ['text' => $text]);

        if (!isset($response['embedding']) || !is_array($response['embedding'])) {
            throw new InternalServerErrorException('Embedding service returned invalid response.');
        }

        /** @var float[] */
        return $response['embedding'];
    }

    /**
     * Embed multiple texts in a single request.
     *
     * @param string[] $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array
    {
        $response = $this->post('/embed/batch', ['texts' => array_values($texts)]);

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
            throw new InternalServerErrorException('Embedding service unavailable: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
            throw new InternalServerErrorException('Embedding service unavailable: ' . $error);
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
