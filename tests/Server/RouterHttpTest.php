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
