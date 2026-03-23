<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

/**
 * HTTP tests for /v3/search/semantic route.
 *
 * Semantic search requires the embedding microservice to be running.
 * Tests that need the service are skipped when it's unavailable.
 */
#[Group('slow')]
class SemanticSearchHttpTest extends ServerTestCase
{
    public function testSemanticRouteExists(): void
    {
        $r = self::httpGet('/v3/search/semantic?version=TEST1');
        // Route exists — should get 422 (validation), not 404
        self::assertNotSame(404, $r['status']);
    }

    public function testMissingQueryReturns422(): void
    {
        $r = self::httpGet('/v3/search/semantic?version=TEST1');
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('query', $body['detail']);
    }

    public function testMissingVersionReturns422(): void
    {
        $r = self::httpGet('/v3/search/semantic?query=creation');
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('version', $body['detail']);
    }

    public function testInvalidVersionReturns422(): void
    {
        $r = self::httpGet('/v3/search/semantic?query=creation&version=FAKE');
        self::assertSame(422, $r['status']);
    }
}
