<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Services\EmbeddingClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmbeddingClient.
 *
 * These tests verify URL resolution and basic construction.
 * Integration tests against the actual embedding service are skipped
 * if the service is not available.
 */
class EmbeddingClientTest extends TestCase
{
    public function testConstructWithExplicitUrl(): void
    {
        $client = new EmbeddingClient('http://test-host:9999');
        self::assertInstanceOf(EmbeddingClient::class, $client);
    }

    public function testIsHealthyReturnsFalseWhenServiceUnavailable(): void
    {
        // Point to a port that's not running anything
        $client = new EmbeddingClient('http://127.0.0.1:19999');
        self::assertFalse($client->isHealthy());
    }

    public function testEmbedThrowsWhenServiceUnavailable(): void
    {
        $client = new EmbeddingClient('http://127.0.0.1:19999');
        $this->expectException(\BibleGet\Api\Http\Exception\InternalServerErrorException::class);
        $this->expectExceptionMessage('Embedding service unavailable');
        $client->embed('test text');
    }
}
