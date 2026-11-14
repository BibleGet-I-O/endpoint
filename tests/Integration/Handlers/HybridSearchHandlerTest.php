<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\HybridSearchHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Services\EmbeddingClient;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fixture recap (TEST1, english): the fulltext keyword leg for "light"
 * ranks Gen 1:3 (#1), Gen 1:4 (#2), Gen 1:5 (#3). The semantic leg is
 * controlled by seeding MiniLM vectors via seedSemantic(): Exod 1:1 gets
 * the query vector itself (#1), Gen 1:5 a near-identical one (#2).
 */
class HybridSearchHandlerTest extends DatabaseTestCase
{
    /** @return list<float> */
    private static function matchVector(int $dim = 384): array
    {
        return array_fill(0, $dim, 0.5);
    }

    /** @return list<float> */
    private static function weakVector(int $dim = 384): array
    {
        $v    = array_fill(0, $dim, 0.5);
        $v[0] = -0.5;
        return $v;
    }

    /** @param list<float> $v */
    private static function vec(array $v): string
    {
        return '[' . implode(',', $v) . ']';
    }

    private function createHandler(?EmbeddingClient $client = null): HybridSearchHandler
    {
        $handler = new HybridSearchHandler(new Psr17Factory(), [], $client);
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        return $handler;
    }

    /** @param list<float>|null $vector */
    private function stubClient(?array $vector = null): EmbeddingClient
    {
        $client = $this->createStub(EmbeddingClient::class);
        $client->method('embed')->willReturn($vector ?? self::matchVector());
        return $client;
    }

    /** Exod 1:1 → semantic #1 (exact match), Gen 1:5 → semantic #2. */
    private function seedSemantic(string $version = 'TEST1'): void
    {
        $pdo = $this->getConnection();
        $pdo->exec('UPDATE "' . $version . '" SET embedding = \'' . self::vec(self::matchVector()) . '\' WHERE book = 2 AND chapter = 1 AND verse = 1');
        $pdo->exec('UPDATE "' . $version . '" SET embedding = \'' . self::vec(self::weakVector()) . '\' WHERE book = 1 AND chapter = 1 AND verse = 5');
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function search(array $params, ?EmbeddingClient $client = null): array
    {
        $handler  = $this->createHandler($client ?? $this->stubClient());
        $request  = ( new ServerRequest('GET', '/v3/search/hybrid') )->withQueryParams($params);
        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string> "VERSION book:chapter:verse" per result row
     */
    private static function refs(array $body): array
    {
        self::assertIsArray($body['results']);
        return array_values(array_map(
            static fn(array $r): string => $r['version'] . ' ' . $r['univbooknum'] . ':' . $r['chapter'] . ':' . $r['verse'],
            $body['results']
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();
        HybridSearchHandler::resetCache();

        $pdo = $this->getConnection();
        try {
            $pdo->query('SELECT 1 FROM "TEST1" LIMIT 1');
        } catch (\PDOException $e) {
            self::markTestSkipped('Test table TEST1 not available: ' . $e->getMessage());
        }
        $pdo->exec('UPDATE "TEST1" SET embedding = NULL, embedding_labse = NULL');
        $pdo->exec('UPDATE "TEST2" SET embedding = NULL, embedding_labse = NULL');
    }

    protected function tearDown(): void
    {
        try {
            $pdo = $this->getConnection();
            $pdo->exec('UPDATE "TEST1" SET embedding = NULL, embedding_labse = NULL');
            $pdo->exec('UPDATE "TEST2" SET embedding = NULL, embedding_labse = NULL');
        } catch (\Throwable) {
            // DB not available (test was skipped)
        }
        parent::tearDown();
    }

    // ── Fusion ──────────────────────────────────────────────

    public function testFusionOrder(): void
    {
        $this->seedSemantic();
        $body = $this->search(['query' => 'light', 'version' => 'TEST1', 'model' => 'minilm']);

        // Gen 1:5: 1/63 + 1/62 (both legs) beats every single-leg row.
        // Gen 1:3 (keyword #1) and Exod 1:1 (semantic #1) tie at 1/61 and on
        // best-rank; verseID (canonical order) breaks the tie.
        self::assertSame(
            ['TEST1 1:1:5', 'TEST1 1:1:3', 'TEST1 2:1:1', 'TEST1 1:1:4'],
            self::refs($body)
        );
        self::assertEqualsWithDelta(1 / 63 + 1 / 62, $body['results'][0]['score'], 1e-12);
        self::assertEqualsWithDelta(1 / 61, $body['results'][1]['score'], 1e-12);
        self::assertSame(['keyword', 'semantic'], $body['info']['legs']);
        self::assertSame(60, $body['info']['k']);
        self::assertSame('minilm', $body['info']['model']);
    }

    public function testComponentsNullness(): void
    {
        $this->seedSemantic();
        $body                      = $this->search(['query' => 'light', 'version' => 'TEST1', 'model' => 'minilm']);
        [$both, $kwOnly, $semOnly] = [$body['results'][0], $body['results'][1], $body['results'][2]];

        self::assertSame(3, $both['components']['keyword']['rank']);
        self::assertIsFloat($both['components']['keyword']['score']);
        self::assertSame(2, $both['components']['semantic']['rank']);
        self::assertIsFloat($both['components']['semantic']['score']);

        self::assertSame(1, $kwOnly['components']['keyword']['rank']);
        self::assertNull($kwOnly['components']['semantic']);

        self::assertNull($semOnly['components']['keyword']);
        self::assertSame(1, $semOnly['components']['semantic']['rank']);
        self::assertEqualsWithDelta(1.0, $semOnly['components']['semantic']['score'], 1e-4);
    }

    public function testResultRowShape(): void
    {
        $this->seedSemantic();
        $body = $this->search(['query' => 'light', 'version' => 'TEST1', 'model' => 'minilm']);
        $row  = $body['results'][0];
        foreach (['version', 'testament', 'text', 'text_plain', 'bookabbrev', 'booknum', 'univbooknum', 'book', 'section', 'chapter', 'verse', 'score', 'canonical_order', 'components'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertArrayNotHasKey('verseID', $row);
        self::assertSame([], $body['errors']);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);

        // canonical_order ranks rows by verseID within the version, not by fused score.
        $orders = array_column($body['results'], 'canonical_order');
        self::assertSame([3, 1, 4, 2], $orders);
    }

    public function testSemanticWeightZeroGivesKeywordOrderWithoutEmbedding(): void
    {
        $client = $this->createMock(EmbeddingClient::class);
        $client->expects(self::never())->method('embed');

        $body = $this->search(['query' => 'light', 'version' => 'TEST1', 'semantic_weight' => '0'], $client);

        self::assertSame(['TEST1 1:1:3', 'TEST1 1:1:4', 'TEST1 1:1:5'], self::refs($body));
        self::assertSame(['keyword'], $body['info']['legs']);
        foreach ($body['results'] as $i => $row) {
            self::assertSame($i + 1, $row['components']['keyword']['rank']);
            self::assertNull($row['components']['semantic']);
        }
    }

    public function testKeywordWeightZeroGivesSemanticOrder(): void
    {
        $this->seedSemantic();
        $body = $this->search(['query' => 'light', 'version' => 'TEST1', 'model' => 'minilm', 'keyword_weight' => '0']);

        self::assertSame(['TEST1 2:1:1', 'TEST1 1:1:5'], self::refs($body));
        self::assertSame(['semantic'], $body['info']['legs']);
        self::assertNull($body['results'][1]['components']['keyword']);
    }

    public function testKAndWeightsChangeFusedScores(): void
    {
        $this->seedSemantic();
        $body = $this->search([
            'query'           => 'light',
            'version'         => 'TEST1',
            'model'           => 'minilm',
            'k'               => '1',
            'keyword_weight'  => '2',
            'semantic_weight' => '0.6',
        ]);

        // Gen 1:3: 2/2 = 1.0; Gen 1:5: 2/4 + 0.6/3 = 0.7; Gen 1:4: 2/3; Exod 1:1: 0.6/2.
        self::assertSame(
            ['TEST1 1:1:3', 'TEST1 1:1:5', 'TEST1 1:1:4', 'TEST1 2:1:1'],
            self::refs($body)
        );
        self::assertSame(1, $body['info']['k']);
        self::assertEqualsWithDelta(2.0, $body['info']['keyword_weight'], 1e-12);
        self::assertEqualsWithDelta(0.6, $body['info']['semantic_weight'], 1e-12);

        // score == Σ w / (k + rank) recomputed from the exposed components.
        foreach ($body['results'] as $row) {
            $expected = 0.0;
            if ($row['components']['keyword'] !== null) {
                $expected += 2 / ( 1 + $row['components']['keyword']['rank'] );
            }
            if ($row['components']['semantic'] !== null) {
                $expected += 0.6 / ( 1 + $row['components']['semantic']['rank'] );
            }
            self::assertEqualsWithDelta($expected, $row['score'], 1e-12);
        }
    }

    public function testLimitTrimsFusedList(): void
    {
        $this->seedSemantic();
        $body = $this->search(['query' => 'light', 'version' => 'TEST1', 'model' => 'minilm', 'limit' => '2']);
        self::assertSame(['TEST1 1:1:5', 'TEST1 1:1:3'], self::refs($body));
        self::assertSame(50, $body['info']['candidates']);
    }

    public function testCandidatesFormula(): void
    {
        self::assertSame(50, HybridSearchHandler::candidatesFor(1));
        self::assertSame(50, HybridSearchHandler::candidatesFor(16));
        self::assertSame(60, HybridSearchHandler::candidatesFor(20));
        self::assertSame(300, HybridSearchHandler::candidatesFor(100));
        self::assertSame(300, HybridSearchHandler::candidatesFor(1000));
    }

    public function testShortQueryRunsSemanticLegOnly(): void
    {
        $this->seedSemantic();
        $body = $this->search(['query' => 'sin', 'version' => 'TEST1', 'model' => 'minilm']);

        self::assertSame(['semantic'], $body['info']['legs']);
        self::assertSame(['TEST1 2:1:1', 'TEST1 1:1:5'], self::refs($body));
        foreach ($body['results'] as $row) {
            self::assertNull($row['components']['keyword']);
            self::assertNotNull($row['components']['semantic']);
        }
    }

    public function testShortQueryWithSemanticWeightZeroThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at least 4 characters');
        $this->search(['query' => 'sin', 'version' => 'TEST1', 'semantic_weight' => '0']);
    }

    public function testDefaultModelIsLabse(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec('UPDATE "TEST1" SET embedding_labse = \'' . self::vec(self::matchVector(768)) . '\' WHERE book = 2 AND chapter = 1 AND verse = 1');

        $body = $this->search(['query' => 'light', 'version' => 'TEST1'], $this->stubClient(self::matchVector(768)));
        self::assertSame('labse', $body['info']['model']);
        self::assertContains('TEST1 2:1:1', self::refs($body));
    }

    public function testThresholdFiltersSemanticLeg(): void
    {
        $this->seedSemantic();
        // Gen 1:5's similarity is ~0.9948 — below 0.999 it drops out of the semantic leg.
        $body  = $this->search(['query' => 'light', 'version' => 'TEST1', 'model' => 'minilm', 'threshold' => '0.999']);
        $gen15 = array_values(array_filter($body['results'], static fn(array $r): bool => $r['verse'] === 5))[0];
        self::assertNull($gen15['components']['semantic']);
        self::assertNotNull($gen15['components']['keyword']);
    }

    // ── Multi-version ───────────────────────────────────────

    public function testMultiVersionFusesGlobally(): void
    {
        $this->seedSemantic('TEST1');
        $this->seedSemantic('TEST2');
        $body = $this->search(['query' => 'light', 'version' => 'test1,TEST2,TEST1', 'model' => 'minilm']);

        $versionsSeen = array_values(array_unique(array_column($body['results'], 'version')));
        sort($versionsSeen);
        self::assertSame(['TEST1', 'TEST2'], $versionsSeen);

        // Leg ranks are global across versions: 6 keyword hits ranked 1..6.
        $kwRanks = array_values(array_filter(array_map(
            static fn(array $r): ?int => $r['components']['keyword']['rank'] ?? null,
            $body['results']
        )));
        sort($kwRanks);
        self::assertSame([1, 2, 3, 4, 5, 6], $kwRanks);

        // Per-version canonical_order partitions, each starting at 1.
        foreach (['TEST1', 'TEST2'] as $v) {
            $orders = array_column(array_filter($body['results'], static fn(array $r): bool => $r['version'] === $v), 'canonical_order');
            sort($orders);
            self::assertSame(range(1, count($orders)), $orders);
        }

        // Fused scores are non-increasing.
        $scores = array_column($body['results'], 'score');
        for ($i = 1; $i < count($scores); $i++) {
            self::assertGreaterThanOrEqual($scores[$i], $scores[$i - 1]);
        }
    }

    public function testMixedTsLanguageRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('/search/hybrid');
        $this->search(['query' => 'light', 'version' => 'TEST1,VGCL']);
    }

    // ── Output formats / CORS ───────────────────────────────

    public function testXmlResponse(): void
    {
        $this->seedSemantic();
        $handler  = $this->createHandler($this->stubClient());
        $request  = ( new ServerRequest('GET', '/v3/search/hybrid') )
            ->withQueryParams(['query' => 'light', 'version' => 'TEST1', 'model' => 'minilm', 'return' => 'xml']);
        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('xml', $response->getHeaderLine('Content-Type'));

        $xml = simplexml_load_string((string) $response->getBody());
        self::assertNotFalse($xml);
        self::assertSame('BibleGetHybridSearch', $xml->getName());
        $first = $xml->results->result[0];
        self::assertNotNull($first);
        self::assertSame('5', (string) $first['verse']);
        self::assertSame('3', (string) $first->components->keyword['rank']);
        self::assertSame('2', (string) $first->components->semantic['rank']);
        $second = $xml->results->result[1];
        self::assertNotNull($second);
        self::assertCount(0, $second->components->semantic);
    }

    public function testOptionsReturns200(): void
    {
        $response = $this->createHandler()->handle(new ServerRequest('OPTIONS', '/v3/search/hybrid'));
        self::assertSame(200, $response->getStatusCode());
    }

    // ── Errors ──────────────────────────────────────────────

    public function testThrows503WhenEmbeddingServiceDown(): void
    {
        $client = $this->createStub(EmbeddingClient::class);
        $client->method('embed')
            ->willThrowException(new ServiceUnavailableException('Embedding service unavailable: connection refused'));

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Semantic search is temporarily unavailable');
        $this->search(['query' => 'light', 'version' => 'TEST1'], $client);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidParamsProvider(): array
    {
        return [
            'missing query'          => [['version' => 'TEST1'], 'query'],
            'blank query'            => [['query' => '   ', 'version' => 'TEST1'], 'query'],
            'missing version'        => [['query' => 'light'], 'version'],
            'invalid version'        => [['query' => 'light', 'version' => 'FAKE'], 'Not a valid version'],
            'invalid model'          => [['query' => 'light', 'version' => 'TEST1', 'model' => 'gpt-x'], 'Invalid model'],
            'k zero'                 => [['query' => 'light', 'version' => 'TEST1', 'k' => '0'], 'Invalid k'],
            'k negative'             => [['query' => 'light', 'version' => 'TEST1', 'k' => '-5'], 'Invalid k'],
            'k fractional'           => [['query' => 'light', 'version' => 'TEST1', 'k' => '1.5'], 'Invalid k'],
            'k non-numeric'          => [['query' => 'light', 'version' => 'TEST1', 'k' => 'abc'], 'Invalid k'],
            'negative kw weight'     => [['query' => 'light', 'version' => 'TEST1', 'keyword_weight' => '-1'], 'Invalid keyword_weight'],
            'non-numeric sem weight' => [['query' => 'light', 'version' => 'TEST1', 'semantic_weight' => 'abc'], 'Invalid semantic_weight'],
            'infinite weight'        => [['query' => 'light', 'version' => 'TEST1', 'semantic_weight' => '1e999'], 'Invalid semantic_weight'],
            'both weights zero'      => [['query' => 'light', 'version' => 'TEST1', 'keyword_weight' => '0', 'semantic_weight' => '0.0'], 'cannot both be 0'],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('invalidParamsProvider')]
    public function testInvalidParamsRejected(array $params, string $message): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);
        $this->createHandler($this->stubClient())->handle(
            ( new ServerRequest('GET', '/v3/search/hybrid') )->withQueryParams($params)
        );
    }
}
