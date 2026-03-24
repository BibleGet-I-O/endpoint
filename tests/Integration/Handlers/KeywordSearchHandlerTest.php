<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\KeywordSearchHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\ServerRequest;

class KeywordSearchHandlerTest extends DatabaseTestCase
{
    private function createHandler(): KeywordSearchHandler
    {
        $handler = new KeywordSearchHandler();
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        return $handler;
    }

    protected function setUp(): void
    {
        parent::setUp();
        KeywordSearchHandler::resetCache();

        $pdo = $this->getConnection();
        try {
            $pdo->query('SELECT 1 FROM "TEST1" LIMIT 1');
            $pdo->query('SELECT 1 FROM "VGCL" LIMIT 1');
        } catch (\PDOException $e) {
            self::markTestSkipped('Required test tables not available: ' . $e->getMessage());
        }
    }

    // ── Fulltext mode (default) with stemming ───────────────

    public function testFulltextSearchReturnsResults(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'light', 'version' => 'TEST1']);
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);
    }

    public function testFulltextStemmingMatchesInflectedForms(): void
    {
        $handler = $this->createHandler();
        // "create" should match "created" with English stemmer
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'create', 'version' => 'TEST1', 'match' => 'fulltext']);
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results'], 'English stemmer should match "created" when searching "create"');

        foreach ($body['results'] as $verse) {
            self::assertStringContainsString('created', strtolower($verse['text']));
        }
    }

    public function testFulltextSimpleFallbackNoStemming(): void
    {
        $handler = $this->createHandler();
        // VGCL uses 'simple' (Latin) — "miserere" should match exactly but not stem
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'miserere', 'version' => 'VGCL', 'match' => 'fulltext']);
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results'], 'Simple dictionary should still match exact tokens');
    }

    public function testDefaultMatchModeIsFulltext(): void
    {
        $handler = $this->createHandler();
        // No match param → should default to fulltext with stemming
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'create', 'version' => 'TEST1']);
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results'], 'Default mode should use fulltext with stemming');
    }

    // ── Exact match mode ────────────────────────────────────

    public function testExactMatchSearch(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'God', 'version' => 'TEST1', 'match' => 'exact']);
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);
    }

    public function testExactMatchDoesNotStem(): void
    {
        $handler = $this->createHandler();
        // "create" should NOT match "created" in exact mode
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'create', 'version' => 'TEST1', 'match' => 'exact']);
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertEmpty($body['results'], 'Exact mode should not stem: "create" should not match "created"');
    }

    // ── Boolean mode ────────────────────────────────────────

    public function testBooleanSearchAndOperator(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'light & good', 'version' => 'TEST1', 'match' => 'boolean']);
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);

        foreach ($body['results'] as $verse) {
            $text = strtolower($verse['text']);
            self::assertStringContainsString('light', $text);
            self::assertStringContainsString('good', $text);
        }
    }

    // ── Legacy backward compatibility ───────────────────────

    public function testLegacyExactmatchParamMapsToExactMode(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'God', 'version' => 'TEST1', 'exactmatch' => 'true']);
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);
    }

    public function testMatchParamOverridesExactmatch(): void
    {
        $handler = $this->createHandler();
        // match=fulltext should take precedence over exactmatch=true
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'create', 'version' => 'TEST1', 'match' => 'fulltext', 'exactmatch' => 'true']);
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        // fulltext with stemming should match "created"
        self::assertNotEmpty($body['results'], 'match param should override legacy exactmatch');
    }

    // ── Validation errors ──────────────────────────────────

    public function testMissingKeywordThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('keyword');
        $request = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['version' => 'TEST1']);
        $handler->handle($request);
    }

    public function testMissingVersionThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('version');
        $request = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'light']);
        $handler->handle($request);
    }

    public function testInvalidVersionThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $request = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'light', 'version' => 'FAKE']);
        $handler->handle($request);
    }

    public function testInvalidMatchModeThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid match mode');
        $request = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'light', 'version' => 'TEST1', 'match' => 'fuzzy']);
        $handler->handle($request);
    }

    public function testShortKeywordFulltextThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('4 characters');
        $request = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'God', 'version' => 'TEST1', 'match' => 'fulltext']);
        $handler->handle($request);
    }

    public function testShortKeywordExactModeAllowed(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'God', 'version' => 'TEST1', 'match' => 'exact']);
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // ── Response format ─────────────────────────────────────

    public function testResultStructure(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'light', 'version' => 'TEST1']);
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);
        $verse = $body['results'][0];

        self::assertArrayHasKey('book', $verse);
        self::assertArrayHasKey('chapter', $verse);
        self::assertArrayHasKey('verse', $verse);
        self::assertArrayHasKey('text', $verse);
        self::assertArrayHasKey('version', $verse);
        self::assertArrayHasKey('bookabbrev', $verse);
        self::assertSame('TEST1', $verse['version']);
    }

    public function testInfoContainsEndpointVersion(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'light', 'version' => 'TEST1']);
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }

    public function testXmlResponse(): void
    {
        $handler  = $this->createHandler();
        $request  = ( new ServerRequest('GET', '/v3/search/keyword') )
            ->withQueryParams(['keyword' => 'light', 'version' => 'TEST1', 'return' => 'xml']);
        $response = $handler->handle($request);

        self::assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));
        $xml = (string) $response->getBody();
        self::assertStringContainsString('<?xml', $xml);
        self::assertStringContainsString('BibleGetSearch', $xml);
    }

    public function testOptionsReturns200(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('OPTIONS', '/v3/search/keyword'));

        self::assertSame(200, $response->getStatusCode());
    }
}
