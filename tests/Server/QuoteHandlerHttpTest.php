<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class QuoteHandlerHttpTest extends ServerTestCase
{
    // ── JSON responses ──────────────────────────────────────

    public function testSingleVerseJson(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/json', $r['headers']['content-type']);

        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('results', $body);
        self::assertArrayHasKey('errors', $body);
        self::assertArrayHasKey('info', $body);
        self::assertCount(1, $body['results']);
        self::assertStringContainsString('beginning', $body['results'][0]['text']);
    }

    public function testVerseRangeJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1-3&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertCount(3, $body['results']);
    }

    public function testWholeChapterJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertCount(10, $body['results']);
    }

    public function testMultipleVersionsJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1,TEST2');
        $body = self::jsonBody($r['body']);
        self::assertCount(2, $body['results']);
        $versions = array_column($body['results'], 'version');
        self::assertContains('TEST1', $versions);
        self::assertContains('TEST2', $versions);
    }

    public function testEnglishNotationJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1:1-3&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertCount(3, $body['results']);
        self::assertSame('ENGLISH', $body['info']['detectedNotation']);
    }

    public function testDiscontinuousVersesJson(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1.3.5&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertCount(3, $body['results']);
    }

    public function testInfoContainsEndpointVersion(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }

    public function testInfoContainsBibleVersionsInfo(): void
    {
        $r    = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('bibleVersionsInfo', $body['info']);
        self::assertArrayHasKey('TEST1', $body['info']['bibleVersionsInfo']);
    }

    public function testResultStructure(): void
    {
        $r     = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1');
        $body  = self::jsonBody($r['body']);
        $verse = $body['results'][0];

        self::assertArrayHasKey('book', $verse);
        self::assertArrayHasKey('chapter', $verse);
        self::assertArrayHasKey('verse', $verse);
        self::assertArrayHasKey('text', $verse);
        self::assertArrayHasKey('version', $verse);
        self::assertArrayHasKey('bookabbrev', $verse);
        self::assertArrayHasKey('originalquery', $verse);
        self::assertArrayNotHasKey('verseID', $verse);
    }

    // ── POST with JSON body ────────────────────────────────

    public function testPostJsonBody(): void
    {
        $r = self::httpPost(
            '/v3/quote',
            json_encode(['query' => 'Genesis1,1', 'version' => 'TEST1']) ?: '{}',
            ['Content-Type' => 'application/json']
        );
        self::assertSame(200, $r['status']);
        $body = self::jsonBody($r['body']);
        self::assertCount(1, $body['results']);
    }

    // ── XML response ───────────────────────────────────────

    public function testXmlResponse(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1&return=xml');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/xml', $r['headers']['content-type']);
        self::assertStringContainsString('<?xml', $r['body']);
        self::assertStringContainsString('BibleQuote', $r['body']);
    }

    // ── HTML response ──────────────────────────────────────

    public function testHtmlResponse(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1&return=html');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('text/html', $r['headers']['content-type']);
        self::assertStringContainsString('bibleQuote', $r['body']);
    }

    // ── Accept header negotiation ──────────────────────────

    public function testAcceptHeaderXml(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1', ['Accept' => 'application/xml']);
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/xml', $r['headers']['content-type']);
    }

    // ── Validation errors ──────────────────────────────────

    public function testMissingQueryParamReturnsError(): void
    {
        $r = self::httpGet('/v3/quote?version=TEST1');
        self::assertSame(422, $r['status']);
        self::assertStringContainsString('application/problem+json', $r['headers']['content-type']);
    }

    public function testMixedNotationReturnsError(): void
    {
        $r = self::httpGet('/v3/quote?query=John3:16.18&version=TEST1');
        self::assertStringContainsString('application/problem+json', $r['headers']['content-type']);
        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('Mixed notation', $body['detail']);
    }

    // ── Bot blocking ───────────────────────────────────────

    public function testBotUserAgentBlocked(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1', ['User-Agent' => 'Googlebot/2.1']);
        self::assertSame(403, $r['status']);
    }
}
