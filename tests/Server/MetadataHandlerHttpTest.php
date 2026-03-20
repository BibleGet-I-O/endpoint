<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class MetadataHandlerHttpTest extends ServerTestCase
{
    // ── Bible books ────────────────────────────────────────

    public function testBibleBooksJson(): void
    {
        $r = self::httpGet('/v3/metadata/biblebooks');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/json', $r['headers']['content-type']);

        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('results', $body);
        self::assertArrayHasKey('languages', $body);
        self::assertArrayHasKey('info', $body);
        self::assertNotEmpty($body['results']);
        self::assertContains('ENGLISH', $body['languages']);
    }

    // ── Bible versions ─────────────────────────────────────

    public function testBibleVersionsJson(): void
    {
        $r = self::httpGet('/v3/metadata/bibleversions');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('validversions', $body);
        self::assertArrayHasKey('validversions_fullname', $body);
        self::assertArrayHasKey('copyrightversions', $body);
        self::assertContains('TEST1', $body['validversions']);
        self::assertContains('TEST2', $body['validversions']);
    }

    public function testCopyrightVersionsFlagged(): void
    {
        $r    = self::httpGet('/v3/metadata/bibleversions');
        $body = self::jsonBody($r['body']);
        self::assertContains('TEST2', $body['copyrightversions']);
        self::assertNotContains('TEST1', $body['copyrightversions']);
    }

    // ── Version index ──────────────────────────────────────

    public function testVersionIndexJson(): void
    {
        $r = self::httpGet('/v3/metadata/versionindex?versions=TEST1');
        self::assertSame(200, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('indexes', $body);
        self::assertArrayHasKey('TEST1', $body['indexes']);

        $idx = $body['indexes']['TEST1'];
        self::assertArrayHasKey('abbreviations', $idx);
        self::assertArrayHasKey('biblebooks', $idx);
        self::assertArrayHasKey('chapter_limit', $idx);
        self::assertArrayHasKey('verse_limit', $idx);
        self::assertArrayHasKey('book_num', $idx);
    }

    public function testVersionIndexMultipleVersions(): void
    {
        $r    = self::httpGet('/v3/metadata/versionindex?versions=TEST1,TEST2');
        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('TEST1', $body['indexes']);
        self::assertArrayHasKey('TEST2', $body['indexes']);
    }

    public function testVersionIndexMissingVersionsParam(): void
    {
        $r = self::httpGet('/v3/metadata/versionindex');
        self::assertStringContainsString('application/problem+json', $r['headers']['content-type']);
        self::assertSame(422, $r['status']);
    }

    // ── Unknown sub-resource ───────────────────────────────

    public function testUnknownSubResourceReturns404(): void
    {
        $r = self::httpGet('/v3/metadata/something_invalid');
        self::assertSame(404, $r['status']);
        self::assertStringContainsString('application/problem+json', $r['headers']['content-type']);
    }

    // ── Legacy query param compatibility ───────────────────

    public function testLegacyQueryParamWorks(): void
    {
        $r = self::httpGet('/v3/metadata?query=bibleversions');
        self::assertSame(200, $r['status']);
        $body = self::jsonBody($r['body']);
        self::assertArrayHasKey('validversions', $body);
    }

    // ── XML response ───────────────────────────────────────

    public function testBibleVersionsXml(): void
    {
        $r = self::httpGet('/v3/metadata/bibleversions?return=xml');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('application/xml', $r['headers']['content-type']);
        self::assertStringContainsString('<?xml', $r['body']);
    }

    // ── Endpoint version in info ───────────────────────────

    public function testInfoContainsEndpointVersion(): void
    {
        $r    = self::httpGet('/v3/metadata/bibleversions');
        $body = self::jsonBody($r['body']);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }
}
