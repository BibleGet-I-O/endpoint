<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Services\EmbeddingClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmbeddingClient.
 *
 * These tests verify URL resolution, basic construction, and circuit breaker behavior.
 */
class EmbeddingClientTest extends TestCase
{
    protected function setUp(): void
    {
        EmbeddingClient::resetCircuitBreaker();
    }

    protected function tearDown(): void
    {
        EmbeddingClient::resetCircuitBreaker();
    }

    public function testConstructWithExplicitUrl(): void
    {
        $client = new EmbeddingClient('http://test-host:9999');
        self::assertInstanceOf(EmbeddingClient::class, $client);
    }

    public function testIsHealthyReturnsFalseWhenServiceUnavailable(): void
    {
        $client = new EmbeddingClient('http://127.0.0.1:19999');
        self::assertFalse($client->isHealthy());
    }

    public function testEmbedThrowsServiceUnavailableWhenDown(): void
    {
        $client = new EmbeddingClient('http://127.0.0.1:19999');
        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Embedding service unavailable');
        $client->embed('test text');
    }

    // ── Circuit breaker ─────────────────────────────────────

    public function testCircuitBreakerTripsAfterRepeatedFailures(): void
    {
        $client = new EmbeddingClient('http://127.0.0.1:19999');

        // First 5 failures should each attempt the actual call
        for ($i = 0; $i < 5; $i++) {
            try {
                $client->embed('test');
            } catch (ServiceUnavailableException) {
                // Expected — actual cURL failure
            }
        }

        // 6th call should be short-circuited by the circuit breaker (no cURL attempt)
        try {
            $client->embed('test');
            self::fail('Expected ServiceUnavailableException from circuit breaker');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('circuit breaker', $e->getMessage());
        }
    }

    public function testCircuitBreakerResetsOnSuccess(): void
    {
        // After a reset, the circuit should be closed
        EmbeddingClient::resetCircuitBreaker();

        $client = new EmbeddingClient('http://127.0.0.1:19999');

        // One failure should not trip the breaker
        try {
            $client->embed('test');
        } catch (ServiceUnavailableException) {
            // Expected
        }

        // Reset simulates a successful probe
        EmbeddingClient::resetCircuitBreaker();

        // Should attempt a real call again (not short-circuited)
        try {
            $client->embed('test');
        } catch (ServiceUnavailableException $e) {
            // This is a real cURL failure, not a circuit breaker rejection
            self::assertStringNotContainsString('circuit breaker', $e->getMessage());
        }
    }
}
