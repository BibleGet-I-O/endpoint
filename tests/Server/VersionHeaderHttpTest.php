<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

/**
 * Every response carries X-BibleGet-Version, errors and auth failures included.
 */
#[Group('slow')]
class VersionHeaderHttpTest extends ServerTestCase
{
    private const PATTERN = '/\A[A-Za-z0-9._+-]{1,64}\z/';

    public function testQuoteResponseCarriesVersionEqualToInfo(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1');
        self::assertSame(200, $r['status']);
        self::assertMatchesRegularExpression(self::PATTERN, $r['headers']['x-bibleget-version']);
        $body = self::jsonBody($r['body']);
        self::assertSame($r['headers']['x-bibleget-version'], $body['info']['ENDPOINT_VERSION']);
    }

    public function testMetadataResponseCarriesVersion(): void
    {
        $r = self::httpGet('/v3/metadata/bibleversions');
        self::assertArrayHasKey('x-bibleget-version', $r['headers']);
    }

    public function testNotFoundResponseCarriesVersion(): void
    {
        $r = self::httpGet('/v3/nonexistent');
        self::assertSame(404, $r['status']);
        self::assertArrayHasKey('x-bibleget-version', $r['headers']);
    }

    public function testCorsExposesVersionHeader(): void
    {
        $r = self::httpGet('/v3/quote?query=Genesis1,1&version=TEST1', ['Origin' => 'https://example.org']);
        self::assertStringContainsString('X-BibleGet-Version', $r['headers']['access-control-expose-headers']);
    }
}
