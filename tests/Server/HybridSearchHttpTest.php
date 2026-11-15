<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

/**
 * HTTP tests for /v3/search/hybrid route.
 *
 * These tests do not require the embedding microservice: they exercise input
 * validation, plus the keyword-only path (semantic_weight=0), which never
 * calls the embedding service.
 */
#[Group('slow')]
class HybridSearchHttpTest extends ServerTestCase
{
    public function testMissingQueryReturns422(): void
    {
        $r = self::httpGet('/v3/search/hybrid?version=TEST1');
        // Route exists — should get 422 (validation), not 404
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('query', $body['detail']);
    }

    public function testMissingVersionReturns422(): void
    {
        $r = self::httpGet('/v3/search/hybrid?query=light');
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('version', $body['detail']);
    }

    public function testInvalidVersionReturns422(): void
    {
        $r = self::httpGet('/v3/search/hybrid?query=light&version=FAKE');
        self::assertSame(422, $r['status']);
    }

    public function testInvalidKReturns422(): void
    {
        $r = self::httpGet('/v3/search/hybrid?query=light&version=TEST1&k=0');
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('Invalid k', $body['detail']);
    }

    public function testKeywordOnlyHybridReturnsFusedRows(): void
    {
        $r = self::httpGet('/v3/search/hybrid?query=light&version=TEST1&semantic_weight=0');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertIsArray($body['results']);
        self::assertNotEmpty($body['results']);
        $first = $body['results'][0];
        self::assertIsArray($first);
        self::assertArrayHasKey('components', $first);
        self::assertArrayHasKey('canonical_order', $first);
        self::assertArrayHasKey('text_plain', $first);
    }
}
