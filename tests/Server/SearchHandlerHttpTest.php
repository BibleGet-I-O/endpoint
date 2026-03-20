<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class SearchHandlerHttpTest extends ServerTestCase
{
    public function testKeywordSearchJson(): void
    {
        $r = self::httpGet('/v3/search?keyword=light&version=TEST1');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/json', $r['headers']['content-type']);

        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('results', $body);
        self::assertNotEmpty($body['results']);

        // "light" appears in Genesis 1:3,4,5
        foreach ($body['results'] as $verse) {
            self::assertStringContainsString('light', strtolower($verse['text']));
        }
    }

    public function testExactMatchSearch(): void
    {
        $r = self::httpGet('/v3/search?keyword=God&version=TEST1&exactmatch=true');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertNotEmpty($body['results']);
    }

    public function testSearchResultStructure(): void
    {
        $r = self::httpGet('/v3/search?keyword=light&version=TEST1');
        $body = self::jsonBody($r['body']);
        $verse = $body['results'][0];

        self::assertArrayHasKey('book', $verse);
        self::assertArrayHasKey('chapter', $verse);
        self::assertArrayHasKey('verse', $verse);
        self::assertArrayHasKey('text', $verse);
        self::assertArrayHasKey('version', $verse);
        self::assertArrayHasKey('bookabbrev', $verse);
        self::assertSame('TEST1', $verse['version']);
    }

    public function testSearchMissingKeywordReturnsError(): void
    {
        $r = self::httpGet('/v3/search?version=TEST1');
        self::assertSame(422, $r['status']);
        self::assertStringContainsString('application/problem+json', $r['headers']['content-type']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('keyword', $body['detail']);
    }

    public function testSearchMissingVersionReturnsError(): void
    {
        $r = self::httpGet('/v3/search?keyword=light');
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('version', $body['detail']);
    }

    public function testSearchInvalidVersionReturnsError(): void
    {
        $r = self::httpGet('/v3/search?keyword=light&version=FAKE');
        self::assertSame(422, $r['status']);
    }

    public function testSearchShortKeywordWithoutExactMatchReturnsError(): void
    {
        $r = self::httpGet('/v3/search?keyword=God&version=TEST1');
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('4 characters', $body['detail']);
    }

    public function testSearchXmlResponse(): void
    {
        $r = self::httpGet('/v3/search?keyword=light&version=TEST1&return=xml');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/xml', $r['headers']['content-type']);
        self::assertStringContainsString('<?xml', $r['body']);
    }

    public function testSearchInfoContainsEndpointVersion(): void
    {
        $r = self::httpGet('/v3/search?keyword=light&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }
}
