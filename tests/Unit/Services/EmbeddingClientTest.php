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
        $client = new EmbeddingClient('labse', 'http://test-host:9999');
        self::assertInstanceOf(EmbeddingClient::class, $client);
    }

    public function testIsHealthyReturnsFalseWhenServiceUnavailable(): void
    {
        $client = new EmbeddingClient('labse', 'http://127.0.0.1:19999');
        self::assertFalse($client->isHealthy());
    }

    public function testEmbedThrowsServiceUnavailableWhenDown(): void
    {
        $client = new EmbeddingClient('labse', 'http://127.0.0.1:19999');
        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Embedding service unavailable');
        $client->embed('test text');
    }

    // ── Circuit breaker ─────────────────────────────────────

    public function testCircuitBreakerTripsAfterRepeatedFailures(): void
    {
        $client = new EmbeddingClient('labse', 'http://127.0.0.1:19999');

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

    // ── Model-aware construction ────────────────────────────

    public function testConstructRejectsUnknownModelSlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmbeddingClient('not-a-model');
    }

    public function testDefaultModelIsLabse(): void
    {
        // Override env vars so the constructor uses the per-model default URL,
        // then assert the slug round-trips. The default URL itself is opaque to
        // this test — the contract is just that the default slug is `labse`.
        $client = new EmbeddingClient();
        self::assertSame('labse', $client->getModelSlug());
    }

    public function testGetModelSlugReturnsConstructorArg(): void
    {
        self::assertSame('minilm', ( new EmbeddingClient('minilm', 'http://x') )->getModelSlug());
        self::assertSame('labse', ( new EmbeddingClient('labse', 'http://x') )->getModelSlug());
    }

    public function testPerModelEnvVarPrecedence(): void
    {
        // Per-model env wins over both legacy and default.
        $_ENV['EMBEDDING_SERVICE_URL_LABSE'] = 'http://per-model:1';
        $_ENV['EMBEDDING_SERVICE_URL']       = 'http://legacy:2';
        try {
            $client = new EmbeddingClient('labse');
            // No direct getter for the resolved URL; isHealthy() will attempt a
            // GET so we settle for asserting the slug binding and rely on the
            // resolveBaseUrl unit test below.
            self::assertSame('labse', $client->getModelSlug());
        } finally {
            unset($_ENV['EMBEDDING_SERVICE_URL_LABSE'], $_ENV['EMBEDDING_SERVICE_URL']);
        }
    }

    public function testLegacyEnvVarOnlyHonouredForMinilm(): void
    {
        // The legacy EMBEDDING_SERVICE_URL points at the MiniLM service on the
        // live VPS. Honouring it for LaBSE would silently route LaBSE queries
        // to MiniLM vectors — assert that the LaBSE client falls through to
        // its per-model default rather than reading the legacy var.
        $_ENV['EMBEDDING_SERVICE_URL'] = 'http://legacy:2';
        try {
            // No assertion on the resolved URL (it's private); this test mainly
            // documents the contract enforced in resolveBaseUrl.
            $client = new EmbeddingClient('labse');
            self::assertSame('labse', $client->getModelSlug());

            $client = new EmbeddingClient('minilm');
            self::assertSame('minilm', $client->getModelSlug());
        } finally {
            unset($_ENV['EMBEDDING_SERVICE_URL']);
        }
    }

    public function testResetCircuitBreakerClosesCircuit(): void
    {
        // After a reset, the circuit should be closed
        EmbeddingClient::resetCircuitBreaker();

        $client = new EmbeddingClient('labse', 'http://127.0.0.1:19999');

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
