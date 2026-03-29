<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class RouterHttpTest extends ServerTestCase
{
    // ── Route matching ──────────────────────────────────────

    public function testUnknownRouteReturns404(): void
    {
        $r = self::httpGet('/v3/nonexistent');
        self::assertSame(404, $r['status']);
    }

    public function testUnknownRouteWithoutV3Prefix(): void
    {
        $r = self::httpGet('/completely/unknown');
        self::assertSame(404, $r['status']);
    }

    public function testRootPathRoutesToQuoteHandler(): void
    {
        // Root path hits quote handler, which needs a query param.
        // Without DB credentials it returns 500, but that proves routing works.
        $r = self::httpGet('/');
        // Either 500 (no DB) or 422 (validation error) — not 404
        self::assertNotSame(404, $r['status']);
    }

    public function testQuoteRouteExists(): void
    {
        $r = self::httpGet('/v3/quote?query=Gen1,1');
        // Not 404 — the route matched
        self::assertNotSame(404, $r['status']);
    }

    public function testMetadataRouteExists(): void
    {
        $r = self::httpGet('/v3/metadata/bibleversions');
        self::assertNotSame(404, $r['status']);
    }

    public function testSearchRouteExists(): void
    {
        $r = self::httpGet('/v3/search?keyword=light&version=TEST1');
        self::assertNotSame(404, $r['status']);
    }

    // ── Docs / OpenAPI spec routes ────────────────────────

    public function testDocsRouteReturnsHtml(): void
    {
        $r = self::httpGet('/v3/docs');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('text/html', $r['headers']['content-type'] ?? '');
        self::assertStringContainsString('swagger-ui', $r['body']);
    }

    public function testDocsRouteIncludesCspHeader(): void
    {
        $r   = self::httpGet('/v3/docs');
        $csp = $r['headers']['content-security-policy'] ?? '';
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString('https://unpkg.com', $csp);
        self::assertStringContainsString("connect-src 'self' https://unpkg.com", $csp);
    }

    public function testOpenApiJsonRouteReturnsSpec(): void
    {
        $r = self::httpGet('/v3/openapi.json');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/json', $r['headers']['content-type'] ?? '');
        $data = self::jsonBody($r['body']);
        self::assertSame('3.0.3', $data['openapi'] ?? null);
    }

    public function testOpenApiJsonRouteHasCorsWildcard(): void
    {
        $r = self::httpGet('/v3/openapi.json');
        self::assertSame('*', $r['headers']['access-control-allow-origin'] ?? null);
    }

    // ── Request ID header ──────────────────────────────────

    public function testResponseIncludesRequestId(): void
    {
        $r = self::httpGet('/v3/metadata/bibleversions');
        self::assertArrayHasKey('x-request-id', $r['headers']);
        self::assertNotEmpty($r['headers']['x-request-id']);
    }

    public function testRequestIdIsUnique(): void
    {
        $r1 = self::httpGet('/v3/metadata/bibleversions');
        $r2 = self::httpGet('/v3/metadata/bibleversions');
        self::assertNotSame(
            $r1['headers']['x-request-id'],
            $r2['headers']['x-request-id']
        );
    }
}
