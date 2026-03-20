<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class ErrorHandlingHttpTest extends ServerTestCase
{
    // ── RFC 9457 problem+json ──────────────────────────────

    public function testMethodNotAllowedReturnsProblemJson(): void
    {
        $r = self::httpDelete('/v3/quote');
        self::assertSame(405, $r['status']);
        self::assertSame('application/problem+json', $r['headers']['content-type']);

        $body = self::jsonBody($r['body']);
        self::assertSame(405, $body['status']);
        self::assertSame('Method Not Allowed', $body['title']);
        self::assertStringContainsString('rfc9110', $body['type']);
        self::assertArrayHasKey('detail', $body);
    }

    public function testUnsupportedMediaTypeReturnsProblemJson(): void
    {
        $r = self::httpPost('/v3/quote', 'some text', ['Content-Type' => 'text/plain']);
        self::assertSame(415, $r['status']);
        self::assertSame('application/problem+json', $r['headers']['content-type']);

        $body = self::jsonBody($r['body']);
        self::assertSame(415, $body['status']);
        self::assertSame('Unsupported Media Type', $body['title']);
    }

    public function testBadJsonReturnsProblemJson(): void
    {
        $r = self::httpPost('/v3/quote', '{invalid json', ['Content-Type' => 'application/json']);
        self::assertSame(400, $r['status']);
        self::assertSame('application/problem+json', $r['headers']['content-type']);

        $body = self::jsonBody($r['body']);
        self::assertSame(400, $body['status']);
        self::assertSame('Bad Request', $body['title']);
        self::assertStringContainsString('Malformed JSON', $body['detail']);
    }

    public function testNotFoundReturnsProblemJson(): void
    {
        // Metadata with unknown sub-resource
        $r = self::httpGet('/v3/metadata/invalid_query');
        self::assertSame(404, $r['status']);
        self::assertSame('application/problem+json', $r['headers']['content-type']);

        $body = self::jsonBody($r['body']);
        self::assertSame(404, $body['status']);
    }

    // ── CORS on error responses ────────────────────────────

    public function testErrorResponseIncludesCorsWithOrigin(): void
    {
        $r = self::httpDelete('/v3/quote', ['Origin' => 'https://example.com']);
        self::assertSame(405, $r['status']);
        self::assertSame('https://example.com', $r['headers']['access-control-allow-origin']);
        self::assertSame('true', $r['headers']['access-control-allow-credentials']);
    }

    public function testErrorResponseIncludesCorsWildcardWithoutOrigin(): void
    {
        $r = self::httpDelete('/v3/quote');
        self::assertSame(405, $r['status']);
        self::assertSame('*', $r['headers']['access-control-allow-origin']);
    }
}
