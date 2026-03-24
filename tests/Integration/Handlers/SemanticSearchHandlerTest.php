<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\SemanticSearchHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Services\EmbeddingClient;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\ServerRequest;

class SemanticSearchHandlerTest extends DatabaseTestCase
{
    private function createHandler(?EmbeddingClient $client = null): SemanticSearchHandler
    {
        $handler = new SemanticSearchHandler([], $client);
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        return $handler;
    }

    protected function setUp(): void
    {
        parent::setUp();
        SemanticSearchHandler::resetCache();

        $pdo = $this->getConnection();
        try {
            $pdo->query('SELECT 1 FROM "TEST1" LIMIT 1');
        } catch (\PDOException $e) {
            self::markTestSkipped('Test table TEST1 not available: ' . $e->getMessage());
        }
    }

    // ── Validation ──────────────────────────────────────────

    public function testMissingQueryThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('query');
        $request = ( new ServerRequest('GET', '/v3/search/semantic') )
            ->withQueryParams(['version' => 'TEST1']);
        $handler->handle($request);
    }

    public function testMissingVersionThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('version');
        $request = ( new ServerRequest('GET', '/v3/search/semantic') )
            ->withQueryParams(['query' => 'passages about creation']);
        $handler->handle($request);
    }

    public function testInvalidVersionThrows(): void
    {
        $handler = $this->createHandler();
        $this->expectException(ValidationException::class);
        $request = ( new ServerRequest('GET', '/v3/search/semantic') )
            ->withQueryParams(['query' => 'passages about creation', 'version' => 'FAKE']);
        $handler->handle($request);
    }

    public function testOptionsReturns200(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('OPTIONS', '/v3/search/semantic'));
        self::assertSame(200, $response->getStatusCode());
    }

    // ── Graceful fallback when embedding service is down ────

    public function testThrows503WithHelpfulMessageWhenServiceDown(): void
    {
        $mockClient = $this->createMock(EmbeddingClient::class);
        $mockClient->method('embed')
            ->willThrowException(new ServiceUnavailableException('Embedding service unavailable: connection refused'));

        $handler = $this->createHandler($mockClient);
        $request = ( new ServerRequest('GET', '/v3/search/semantic') )
            ->withQueryParams(['query' => 'passages about creation', 'version' => 'TEST1']);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('/v3/search/keyword');
        $handler->handle($request);
    }

    // ── With mock embedding client ──────────────────────────

    public function testSemanticSearchWithMockEmbedding(): void
    {
        // Create a mock that returns a non-zero vector (zero vectors cause NaN in cosine distance)
        $mockClient = $this->createMock(EmbeddingClient::class);
        $mockClient->method('embed')->willReturn(array_fill(0, 384, 0.1));

        $handler = $this->createHandler($mockClient);
        $request = ( new ServerRequest('GET', '/v3/search/semantic') )
            ->withQueryParams(['query' => 'passages about creation', 'version' => 'TEST1']);

        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('results', $body);
        self::assertArrayHasKey('info', $body);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }

    public function testSemanticSearchResultHasSimilarityField(): void
    {
        // Seed a known embedding into a verse so we get a real similarity result.
        // Use a non-zero vector to match against.
        $pdo = $this->getConnection();

        // Set a known embedding on verse 1 (Gen 1:1)
        $testVector = array_fill(0, 384, 0.1);
        $vectorStr  = '[' . implode(',', $testVector) . ']';
        $pdo->exec('UPDATE "TEST1" SET embedding = \'' . $vectorStr . '\' WHERE book = 1 AND chapter = 1 AND verse = 1');

        $mockClient = $this->createMock(EmbeddingClient::class);
        $mockClient->method('embed')->willReturn($testVector);

        $handler = $this->createHandler($mockClient);
        $request = ( new ServerRequest('GET', '/v3/search/semantic') )
            ->withQueryParams(['query' => 'creation', 'version' => 'TEST1', 'limit' => '5']);

        $response = $handler->handle($request);
        $body     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results'], 'Should find at least the verse with a matching embedding');

        $first = $body['results'][0];
        self::assertArrayHasKey('similarity', $first);
        self::assertGreaterThan(0.0, $first['similarity']);
        self::assertArrayHasKey('text', $first);
        self::assertArrayHasKey('version', $first);
    }

    public function testThresholdFiltersResults(): void
    {
        $pdo = $this->getConnection();

        // Set a known embedding on one verse
        $testVector = array_fill(0, 384, 0.1);
        $vectorStr  = '[' . implode(',', $testVector) . ']';
        $pdo->exec('UPDATE "TEST1" SET embedding = \'' . $vectorStr . '\' WHERE book = 1 AND chapter = 1 AND verse = 1');

        $mockClient = $this->createMock(EmbeddingClient::class);
        $mockClient->method('embed')->willReturn($testVector);

        // With threshold=0.999, the identical vector should still match (similarity ≈ 1.0)
        $handler  = $this->createHandler($mockClient);
        $request  = ( new ServerRequest('GET', '/v3/search/semantic') )
            ->withQueryParams(['query' => 'creation', 'version' => 'TEST1', 'threshold' => '0.999']);
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);
    }
}
