<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class CorsHttpTest extends ServerTestCase
{
    public function testPreflightReturns204(): void
    {
        $r = self::httpOptions('/v3/quote', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);
        self::assertSame(204, $r['status']);
    }

    public function testPreflightSetsAllowOrigin(): void
    {
        $r = self::httpOptions('/v3/quote', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'GET',
        ]);
        self::assertSame('https://example.com', $r['headers']['access-control-allow-origin']);
    }

    public function testPreflightSetsAllowMethods(): void
    {
        $r = self::httpOptions('/v3/quote', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);
        $methods = $r['headers']['access-control-allow-methods'];
        self::assertStringContainsString('GET', $methods);
        self::assertStringContainsString('POST', $methods);
    }

    public function testPreflightSetsMaxAge(): void
    {
        $r = self::httpOptions('/v3/quote', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'GET',
        ]);
        self::assertSame('86400', $r['headers']['access-control-max-age']);
    }

    public function testPreflightSetsAllowHeaders(): void
    {
        $r = self::httpOptions('/v3/quote', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, Accept',
        ]);
        $allowedHeaders = strtolower($r['headers']['access-control-allow-headers'] ?? '');
        self::assertStringContainsString('content-type', $allowedHeaders);
        self::assertStringContainsString('accept', $allowedHeaders);
    }

    public function testPreflightSetsVaryHeaders(): void
    {
        $r = self::httpOptions('/v3/quote', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'GET',
        ]);
        $vary = strtolower($r['headers']['vary'] ?? '');
        self::assertStringContainsString('origin', $vary);
    }

    public function testRegularOptionsWithoutOriginReturnsAllow(): void
    {
        $r = self::httpOptions('/v3/quote');
        self::assertSame(200, $r['status']);
        self::assertArrayHasKey('allow', $r['headers']);
        self::assertStringContainsString('GET', $r['headers']['allow']);
    }

    public function testSuccessResponseIncludesCorsOrigin(): void
    {
        $r = self::httpGet('/v3/metadata/bibleversions', [
            'Origin' => 'https://myapp.com',
        ]);
        self::assertSame('https://myapp.com', $r['headers']['access-control-allow-origin']);
        self::assertSame('true', $r['headers']['access-control-allow-credentials']);
    }

    public function testSuccessResponseWildcardWithoutOrigin(): void
    {
        $r = self::httpGet('/v3/metadata/bibleversions');
        self::assertSame('*', $r['headers']['access-control-allow-origin']);
    }
}
