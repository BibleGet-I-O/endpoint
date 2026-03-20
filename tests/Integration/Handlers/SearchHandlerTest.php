<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\SearchHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\ServerRequest;

class SearchHandlerTest extends DatabaseTestCase
{
    private function createHandler(): SearchHandler
    {
        $handler = new SearchHandler();
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        return $handler;
    }

    // ── Keyword search ─────────────────────────────────────

    public function testKeywordSearchJson(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('GET', '/v3/search?keyword=light&version=TEST1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('results', $body);
        self::assertNotEmpty($body['results']);

        foreach ($body['results'] as $verse) {
            self::assertStringContainsString('light', strtolower($verse['text']));
        }
    }

    public function testSearchResultStructure(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('GET', '/v3/search?keyword=light&version=TEST1'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        $verse = $body['results'][0];

        self::assertArrayHasKey('book', $verse);
        self::assertArrayHasKey('chapter', $verse);
        self::assertArrayHasKey('verse', $verse);
        self::assertArrayHasKey('text', $verse);
        self::assertArrayHasKey('version', $verse);
        self::assertArrayHasKey('bookabbrev', $verse);
        self::assertSame('TEST1', $verse['version']);
    }

    // ── Exact match ────────────────────────────────────────

    public function testExactMatchSearch(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('GET', '/v3/search?keyword=God&version=TEST1&exactmatch=true'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);
    }

    // ── Validation errors ──────────────────────────────────

    public function testMissingKeywordThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('keyword');
        $handler->handle(new ServerRequest('GET', '/v3/search?version=TEST1'));
    }

    public function testMissingVersionThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('version');
        $handler->handle(new ServerRequest('GET', '/v3/search?keyword=light'));
    }

    public function testInvalidVersionThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $handler->handle(new ServerRequest('GET', '/v3/search?keyword=light&version=FAKE'));
    }

    public function testShortKeywordWithoutExactMatchThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('4 characters');
        $handler->handle(new ServerRequest('GET', '/v3/search?keyword=God&version=TEST1'));
    }

    // ── XML response ───────────────────────────────────────

    public function testXmlResponse(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('GET', '/v3/search?keyword=light&version=TEST1&return=xml'));

        self::assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));
        $xml = (string) $response->getBody();
        self::assertStringContainsString('<?xml', $xml);
        self::assertStringContainsString('BibleGetSearch', $xml);
    }

    // ── HTML response ──────────────────────────────────────

    public function testHtmlResponse(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('GET', '/v3/search?keyword=light&version=TEST1&return=html'));

        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    // ── Info ───────────────────────────────────────────────

    public function testInfoContainsEndpointVersion(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('GET', '/v3/search?keyword=light&version=TEST1'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }

    // ── OPTIONS ────────────────────────────────────────────

    public function testOptionsReturns200(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('OPTIONS', '/v3/search'));

        self::assertSame(200, $response->getStatusCode());
    }
}
