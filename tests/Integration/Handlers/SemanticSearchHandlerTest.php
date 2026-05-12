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
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

class SemanticSearchHandlerTest extends DatabaseTestCase
{
    private function createHandler(?EmbeddingClient $client = null): SemanticSearchHandler
    {
        $handler = new SemanticSearchHandler(new Psr17Factory(), [], $client);
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

    public function testSemanticSearchResultHasScoreField(): void
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
        self::assertArrayHasKey('score', $first);
        self::assertArrayNotHasKey('similarity', $first, 'similarity field renamed to score (issue #110)');
        self::assertGreaterThan(0.0, $first['score']);
        self::assertArrayHasKey('text', $first);
        self::assertArrayHasKey('version', $first);
        self::assertArrayHasKey('canonical_order', $first);
        self::assertIsInt($first['canonical_order']);
        self::assertGreaterThanOrEqual(1, $first['canonical_order']);
        self::assertArrayNotHasKey('verseID', $first, 'verseID must never cross the API boundary');
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

    // ── Multi-version `version=A,B` (issue #110) ────────────

    public function testMultiVersionInterleaved(): void
    {
        $pdo = $this->getConnection();

        // Seed the same vector on Gen 1:1 of both TEST1 and TEST2
        $testVector = array_fill(0, 384, 0.1);
        $vectorStr  = '[' . implode(',', $testVector) . ']';
        $pdo->exec('UPDATE "TEST1" SET embedding = \'' . $vectorStr . '\' WHERE book = 1 AND chapter = 1 AND verse = 1');
        $pdo->exec('UPDATE "TEST2" SET embedding = \'' . $vectorStr . '\' WHERE book = 1 AND chapter = 1 AND verse = 1');

        $mockClient = $this->createMock(EmbeddingClient::class);
        $mockClient->method('embed')->willReturn($testVector);

        $handler  = $this->createHandler($mockClient);
        $request  = ( new ServerRequest('GET', '/v3/search/semantic') )
            ->withQueryParams(['query' => 'creation', 'version' => 'TEST1,TEST2', 'limit' => '10']);
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);

        $versionsSeen = array_unique(array_column($body['results'], 'version'));
        sort($versionsSeen);
        self::assertSame(['TEST1', 'TEST2'], $versionsSeen);

        // Per-version partition: each version's first row must have canonical_order=1
        foreach (['TEST1', 'TEST2'] as $v) {
            $perVersion = array_values(array_filter(
                $body['results'],
                static fn(array $r): bool => $r['version'] === $v
            ));
            self::assertNotEmpty($perVersion);
            $orders = array_column($perVersion, 'canonical_order');
            self::assertSame(1, min($orders), "First canonical_order for {$v} should be 1");
        }
    }

    public function testCanonicalOrderRanksByVerseIDNotByScore(): void
    {
        $pdo = $this->getConnection();

        // Wipe any embeddings other tests have left behind on TEST1, then
        // seed exactly two verses with known scores so we control the result
        // set entirely.
        $pdo->exec('UPDATE "TEST1" SET embedding = NULL');

        // Higher-similarity verse on a LATER canonical position (book 2),
        // weak verse on the EARLIEST canonical position (book 1, ch 1, v 1).
        // Use distinct directions (not uniform vectors) so cosine similarity
        // actually differs — uniform vectors are direction-identical regardless
        // of magnitude.
        $matchVector   = array_fill(0, 384, 0.5);
        $matchStr      = '[' . implode(',', $matchVector) . ']';
        $weakVector    = array_fill(0, 384, 0.5);
        $weakVector[0] = -0.5;
        $weakStr       = '[' . implode(',', $weakVector) . ']';
        $pdo->exec('UPDATE "TEST1" SET embedding = \'' . $matchStr . '\' WHERE book = 2 AND chapter = 1 AND verse = 1');
        $pdo->exec('UPDATE "TEST1" SET embedding = \'' . $weakStr  . '\' WHERE book = 1 AND chapter = 1 AND verse = 1');

        $mockClient = $this->createMock(EmbeddingClient::class);
        $mockClient->method('embed')->willReturn($matchVector);

        $handler = $this->createHandler($mockClient);
        $request = ( new ServerRequest('GET', '/v3/search/semantic') )
            ->withQueryParams(['query' => 'creation', 'version' => 'TEST1', 'limit' => '10']);

        $response = $handler->handle($request);
        $body     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(2, $body['results']);

        // Array is in similarity-desc order (book 2 first, book 1 second),
        // but canonical_order tracks verseID — so the second-array-position
        // row (book 1, smaller verseID) has canonical_order = 1, and the
        // first row (book 2, larger verseID) has canonical_order = 2.
        self::assertSame(2, $body['results'][0]['canonical_order']);
        self::assertSame(1, $body['results'][1]['canonical_order']);
        self::assertGreaterThan(
            $body['results'][1]['score'],
            $body['results'][0]['score'],
            'Array ordering remains similarity-desc'
        );
    }
}
