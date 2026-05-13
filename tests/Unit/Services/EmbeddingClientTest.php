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

    /**
     * Read the private `$baseUrl` set by resolveBaseUrl(). Reflection is the
     * only way to observe it without making a network call or widening the
     * public API just for tests.
     */
    private static function resolvedBaseUrl(EmbeddingClient $client): string
    {
        $prop = new \ReflectionProperty(EmbeddingClient::class, 'baseUrl');
        $val  = $prop->getValue($client);
        self::assertIsString($val);
        return $val;
    }

    public function testPerModelEnvVarPrecedence(): void
    {
        // Per-model env wins over both legacy and default for both slugs.
        $_ENV['EMBEDDING_SERVICE_URL_LABSE']  = 'http://labse-host:1';
        $_ENV['EMBEDDING_SERVICE_URL_MINILM'] = 'http://minilm-host:2';
        $_ENV['EMBEDDING_SERVICE_URL']        = 'http://legacy:3';
        try {
            self::assertSame(
                'http://labse-host:1',
                self::resolvedBaseUrl(new EmbeddingClient('labse'))
            );
            self::assertSame(
                'http://minilm-host:2',
                self::resolvedBaseUrl(new EmbeddingClient('minilm'))
            );
        } finally {
            unset(
                $_ENV['EMBEDDING_SERVICE_URL_LABSE'],
                $_ENV['EMBEDDING_SERVICE_URL_MINILM'],
                $_ENV['EMBEDDING_SERVICE_URL']
            );
        }
    }

    public function testLegacyEnvVarOnlyHonouredForMinilm(): void
    {
        // The legacy EMBEDDING_SERVICE_URL points at the MiniLM service on the
        // live VPS. Honouring it for LaBSE would silently route LaBSE queries
        // to MiniLM vectors — assert that the LaBSE client falls through to
        // its per-model default (port 8002) rather than reading the legacy var.
        $_ENV['EMBEDDING_SERVICE_URL'] = 'http://legacy-minilm:9000';
        try {
            self::assertSame(
                'http://legacy-minilm:9000',
                self::resolvedBaseUrl(new EmbeddingClient('minilm')),
                'minilm should honour the legacy env var for back-compat'
            );
            self::assertSame(
                'http://127.0.0.1:8002',
                self::resolvedBaseUrl(new EmbeddingClient('labse')),
                'labse must fall through to its dev default, NOT the legacy var'
            );
        } finally {
            unset($_ENV['EMBEDDING_SERVICE_URL']);
        }
    }

    public function testDefaultUrlsWhenNoEnvVarsSet(): void
    {
        // Belt-and-braces: clear every env-var surface (resolveBaseUrl also
        // reads $_SERVER and getenv()) before asserting the per-model defaults.
        $vars  = ['EMBEDDING_SERVICE_URL', 'EMBEDDING_SERVICE_URL_LABSE', 'EMBEDDING_SERVICE_URL_MINILM'];
        $saved = [];
        foreach ($vars as $name) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        try {
            self::assertSame('http://127.0.0.1:8002', self::resolvedBaseUrl(new EmbeddingClient('labse')));
            self::assertSame('http://127.0.0.1:8000', self::resolvedBaseUrl(new EmbeddingClient('minilm')));
        } finally {
            foreach ($vars as $name) {
                [$env, $server, $process] = $saved[$name];
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($process !== false) {
                    putenv($name . '=' . $process);
                }
            }
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
