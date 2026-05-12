<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\QuoteHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;

class QuoteHandlerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    private function createHandler(): QuoteHandler
    {
        $handler = new QuoteHandler(new Psr17Factory());
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        return $handler;
    }

    // ── JSON responses ──────────────────────────────────────

    public function testSingleVerseJson(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1&version=TEST1');
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(1, $body['results']);
        self::assertStringContainsString('beginning', $body['results'][0]['text']);
        self::assertEmpty($body['errors']);
    }

    public function testVerseRangeJson(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1-5&version=TEST1');
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(5, $body['results']);
    }

    public function testWholeChapterJson(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis2&version=TEST1');
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(8, $body['results']);
    }

    public function testDiscontinuousVersesJson(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1.3.5&version=TEST1');
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(3, $body['results']);
    }

    public function testMultipleVersionsJson(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1&version=TEST1,TEST2');
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(2, $body['results']);
        $versions = array_column($body['results'], 'version');
        self::assertContains('TEST1', $versions);
        self::assertContains('TEST2', $versions);
    }

    public function testInfoSection(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1&version=TEST1');
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
        self::assertSame('EUROPEAN', $body['info']['detectedNotation']);
        self::assertArrayHasKey('bibleVersionsInfo', $body['info']);
        self::assertArrayHasKey('TEST1', $body['info']['bibleVersionsInfo']);
    }

    public function testEnglishNotation(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1:1&version=TEST1');
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('ENGLISH', $body['info']['detectedNotation']);
        self::assertCount(1, $body['results']);
    }

    // ── POST with JSON body ────────────────────────────────

    public function testPostWithJsonBody(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('POST', '/v3/quote') )
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(json_encode(['query' => 'Genesis1,1', 'version' => 'TEST1']) ?: '{}'));
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(1, $body['results']);
    }

    // ── XML response ───────────────────────────────────────

    public function testXmlResponse(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1&version=TEST1&return=xml');
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));

        $xml = (string) $response->getBody();
        self::assertStringContainsString('<?xml', $xml);
        self::assertStringContainsString('BibleQuote', $xml);
        self::assertStringContainsString('beginning', $xml);
    }

    // ── HTML response ──────────────────────────────────────

    public function testHtmlResponse(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1&version=TEST1&return=html');
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));

        $html = (string) $response->getBody();
        self::assertStringContainsString('results bibleQuote', $html);
        self::assertStringContainsString('beginning', $html);
        self::assertStringContainsString('ENDPOINT_VERSION', $html);
    }

    // ── Accept header negotiation ──────────────────────────

    public function testAcceptHeaderXml(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/quote?query=Genesis1,1&version=TEST1') )
            ->withHeader('Accept', 'application/xml');
        $response = $handler->handle($request);

        self::assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));
    }

    // ── CORS headers on response ───────────────────────────

    public function testCorsHeadersWithOrigin(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/quote?query=Genesis1,1&version=TEST1') )
            ->withHeader('Origin', 'https://example.com');
        $response = $handler->handle($request);

        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsHeadersWithoutOrigin(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1&version=TEST1');
        $response = $handler->handle($request);

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    // ── OPTIONS preflight ──────────────────────────────────

    public function testOptionsPreflightResponse(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('OPTIONS', '/v3/quote') )
            ->withHeader('Origin', 'https://example.com')
            ->withHeader('Access-Control-Request-Method', 'POST');
        $response = $handler->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString('GET', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    // ── Validation errors ──────────────────────────────────

    public function testMissingQueryThrows(): void
    {
        $handler = $this->createHandler();
        $request = new ServerRequest('GET', '/v3/quote?version=TEST1');

        $this->expectException(\BibleGet\Api\Http\Exception\ValidationException::class);
        $handler->handle($request);
    }

    public function testMixedNotationThrows(): void
    {
        $handler = $this->createHandler();
        $request = new ServerRequest('GET', '/v3/quote?query=John3:16.18&version=TEST1');

        $this->expectException(\BibleGet\Api\Http\Exception\ValidationException::class);
        $this->expectExceptionMessage('Mixed notation');
        $handler->handle($request);
    }

    // ── Bot blocking ───────────────────────────────────────

    public function testBotUserAgentBlocked(): void
    {
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/quote?query=Genesis1,1&version=TEST1') )
            ->withHeader('User-Agent', 'Googlebot/2.1');

        $this->expectException(\BibleGet\Api\Http\Exception\ForbiddenException::class);
        $this->expectExceptionMessage('bot access');
        $handler->handle($request);
    }

    // ── canonical_order field (issue #110) ──────────────────

    public function testCanonicalOrderPresentSingleVersion(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1-5&version=TEST1');
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(5, $body['results']);

        $orders = [];
        foreach ($body['results'] as $row) {
            self::assertArrayHasKey('canonical_order', $row);
            self::assertIsInt($row['canonical_order']);
            self::assertGreaterThanOrEqual(1, $row['canonical_order']);
            self::assertArrayNotHasKey('verseID', $row, 'verseID must never cross the API boundary');
            $orders[] = $row['canonical_order'];
        }

        // Single version → 1..5 in canonical order, monotonic with array order
        self::assertSame([1, 2, 3, 4, 5], $orders);
    }

    public function testCanonicalOrderPerVersionPartition(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/v3/quote?query=Genesis1,1-3&version=TEST1,TEST2');
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(6, $body['results']);

        // Per-version partition: each version restarts at canonical_order=1.
        // The handler returns rows in canonical-key order per version, so the
        // i-th row of each per-version subset must carry canonical_order=i+1
        // — a tighter check than just "the set of values is {1,2,3}".
        foreach (['TEST1', 'TEST2'] as $v) {
            $perVersion = array_values(array_filter(
                $body['results'],
                static fn(array $r): bool => $r['version'] === $v
            ));
            self::assertCount(3, $perVersion);
            foreach ($perVersion as $i => $row) {
                self::assertSame(
                    $i + 1,
                    $row['canonical_order'],
                    "canonical_order for {$v} row {$i} should equal " . ( $i + 1 )
                );
            }
        }

        foreach ($body['results'] as $row) {
            self::assertArrayNotHasKey('verseID', $row);
        }
    }
}
