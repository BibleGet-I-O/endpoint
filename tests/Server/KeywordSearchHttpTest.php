<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class KeywordSearchHttpTest extends ServerTestCase
{
    // ── /v3/search/keyword route ────────────────────────────

    public function testKeywordRouteReturnsResults(): void
    {
        $r = self::httpGet('/v3/search/keyword?keyword=light&version=TEST1');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('results', $body);
        self::assertNotEmpty($body['results']);
    }

    public function testKeywordRouteStemmingWorks(): void
    {
        // "create" should match "created" via English stemmer
        $r = self::httpGet('/v3/search/keyword?keyword=create&version=TEST1&match=fulltext');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertNotEmpty($body['results'], 'Stemming should match "created" for query "create"');
    }

    public function testKeywordRouteExactMode(): void
    {
        $r = self::httpGet('/v3/search/keyword?keyword=God&version=TEST1&match=exact');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertNotEmpty($body['results']);
    }

    public function testKeywordRouteBooleanMode(): void
    {
        $r = self::httpGet('/v3/search/keyword?keyword=light+%26+good&version=TEST1&match=boolean');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertNotEmpty($body['results']);
    }

    public function testKeywordRouteInvalidMatchMode(): void
    {
        $r = self::httpGet('/v3/search/keyword?keyword=light&version=TEST1&match=fuzzy');
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('Invalid match mode', $body['detail']);
    }

    public function testKeywordRouteInfoContainsEndpointVersion(): void
    {
        $r    = self::httpGet('/v3/search/keyword?keyword=light&version=TEST1');
        $body = self::jsonBody($r['body']);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }

    // ── /v3/search backward-compatible alias ────────────────

    public function testSearchAliasStillWorks(): void
    {
        $r = self::httpGet('/v3/search?keyword=light&version=TEST1');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('results', $body);
        self::assertNotEmpty($body['results']);
    }

    public function testSearchAliasLegacyExactmatch(): void
    {
        $r = self::httpGet('/v3/search?keyword=God&version=TEST1&exactmatch=true');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertNotEmpty($body['results']);
    }

    public function testSearchAliasAcceptsMatchParam(): void
    {
        $r = self::httpGet('/v3/search?keyword=create&version=TEST1&match=fulltext');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertNotEmpty($body['results'], 'Alias should support match param with stemming');
    }

    // ── Unknown search sub-route ────────────────────────────

    public function testUnknownSearchSubRouteReturns404(): void
    {
        $r = self::httpGet('/v3/search/nonexistent?keyword=light&version=TEST1');
        self::assertSame(404, $r['status']);
    }
}
