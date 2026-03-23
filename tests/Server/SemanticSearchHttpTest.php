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
        // Even without the embedding service, missing params should give 422, not 404
        $r = self::httpGet('/v3/search/semantic?version=TEST1');
        // 422 = route exists but query param missing, 500 = embedding service unavailable
        self::assertContains($r['status'], [422, 500]);
    }

    public function testMissingQueryReturns422(): void
    {
        $r = self::httpGet('/v3/search/semantic?version=TEST1');
        // Could be 422 (validation) or 500 (if it tries to connect to embedding service first)
        // The handler validates params before calling the service, so expect 422
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
