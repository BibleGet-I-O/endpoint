<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\MetadataHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\ServerRequest;

class MetadataHandlerTest extends DatabaseTestCase
{
    private function createHandler(array $pathParams = []): MetadataHandler
    {
        $handler = new MetadataHandler($pathParams);
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        return $handler;
    }

    // ── Bible books ────────────────────────────────────────

    public function testBibleBooksJson(): void
    {
        $handler  = $this->createHandler(['biblebooks']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/biblebooks'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('results', $body);
        self::assertArrayHasKey('languages', $body);
        self::assertNotEmpty($body['results']);
        self::assertContains('ENGLISH', $body['languages']);
    }

    public function testBibleBooksContainsGenesis(): void
    {
        $handler  = $this->createHandler(['biblebooks']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/biblebooks'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results'], 'results should not be empty');
        self::assertArrayHasKey(0, $body['results'], 'results[0] should exist');
        // First book should contain "Genesis"
        $found = false;
        foreach ($body['results'][0] as $langData) {
            if (is_array($langData) && in_array('Genesis', $langData)) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Genesis should be in the first book entry');
    }

    // ── Bible versions ─────────────────────────────────────

    public function testBibleVersionsJson(): void
    {
        $handler  = $this->createHandler(['bibleversions']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/bibleversions'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('validversions', $body);
        self::assertContains('TEST1', $body['validversions']);
        self::assertContains('TEST2', $body['validversions']);
    }

    public function testBibleVersionsFullnameStructure(): void
    {
        $handler  = $this->createHandler(['bibleversions']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/bibleversions'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('TEST1', $body['validversions_fullname']);
        // Fullname is pipe-delimited: "fullname|year|language|imprimatur|canon|copyright_holder|notes"
        $parts = explode('|', $body['validversions_fullname']['TEST1']);
        self::assertCount(7, $parts);
        self::assertSame('Test Bible Version 1', $parts[0]);
    }

    public function testCopyrightVersionsFlagged(): void
    {
        $handler  = $this->createHandler(['bibleversions']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/bibleversions'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertContains('TEST2', $body['copyrightversions']);
        self::assertNotContains('TEST1', $body['copyrightversions']);
    }

    // ── Version index ──────────────────────────────────────

    public function testVersionIndexJson(): void
    {
        $handler  = $this->createHandler(['versionindex']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/versionindex?versions=TEST1'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
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
        $handler  = $this->createHandler(['versionindex']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/versionindex?versions=TEST1,TEST2'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('TEST1', $body['indexes']);
        self::assertArrayHasKey('TEST2', $body['indexes']);
    }

    public function testVersionIndexMissingVersionsThrows(): void
    {
        $handler = $this->createHandler(['versionindex']);
        $this->expectException(ValidationException::class);
        $handler->handle(new ServerRequest('GET', '/v3/metadata/versionindex'));
    }

    // ── Legacy query param ─────────────────────────────────

    public function testLegacyQueryParam(): void
    {
        $handler  = $this->createHandler(); // no path params
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata?query=bibleversions'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('validversions', $body);
    }

    // ── Unknown sub-resource ───────────────────────────────

    public function testUnknownSubResourceThrows(): void
    {
        $handler = $this->createHandler(['something_invalid']);
        $this->expectException(NotFoundException::class);
        $handler->handle(new ServerRequest('GET', '/v3/metadata/something_invalid'));
    }

    // ── XML response ───────────────────────────────────────

    public function testXmlResponse(): void
    {
        $handler  = $this->createHandler(['bibleversions']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/bibleversions?return=xml'));

        self::assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));
        $xml = (string) $response->getBody();
        self::assertStringContainsString('<?xml', $xml);
        self::assertStringContainsString('BibleGetMetadata', $xml);
    }

    // ── HTML response ──────────────────────────────────────

    public function testHtmlResponse(): void
    {
        $handler  = $this->createHandler(['bibleversions']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/bibleversions?return=html'));

        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    // ── Info section ───────────────────────────────────────

    public function testInfoContainsEndpointVersion(): void
    {
        $handler  = $this->createHandler(['bibleversions']);
        $response = $handler->handle(new ServerRequest('GET', '/v3/metadata/bibleversions'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }

    // ── OPTIONS ────────────────────────────────────────────

    public function testOptionsReturns200(): void
    {
        $handler  = $this->createHandler(['bibleversions']);
        $request  = new ServerRequest('OPTIONS', '/v3/metadata/bibleversions');
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
