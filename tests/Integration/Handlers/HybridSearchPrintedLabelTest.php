<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\HybridSearchHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Services\EmbeddingClient;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/**
 * /search/hybrid reports a verse printed under a label in another chapter by
 * that label (fixture TEST3), from either leg.
 */
final class HybridSearchPrintedLabelTest extends DatabaseTestCase
{
    /** @return list<float> */
    private static function vector(): array
    {
        return array_pad([0.0, 0.0, 1.0], 384, 0.0);
    }

    protected function setUp(): void
    {
        parent::setUp();
        HybridSearchHandler::resetCache();
        $pdo = $this->getConnection();
        try {
            $pdo->query('SELECT 1 FROM "TEST3" LIMIT 1');
        } catch (\PDOException $e) {
            self::markTestSkipped('Test table TEST3 not available: ' . $e->getMessage());
        }
        $pdo->exec('UPDATE "TEST3" SET embedding = \'[' . implode(',', self::vector()) . ']\' WHERE verseequiv = \'12:1\'');
    }

    protected function tearDown(): void
    {
        try {
            $this->getConnection()->exec('UPDATE "TEST3" SET embedding = NULL');
        } catch (\Throwable) {
            // DB not available (test was skipped)
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string> $params
     * @return list<array{int, int, mixed}>
     */
    private function labels(array $params): array
    {
        $client = $this->createStub(EmbeddingClient::class);
        $client->method('embed')->willReturn(self::vector());
        $handler = new HybridSearchHandler(new Psr17Factory(), [], $client);
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON]);
        $response = $handler->handle(( new ServerRequest('GET', '/v3/search/hybrid') )->withQueryParams($params));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['results']);
        $rows = [];
        foreach ($body['results'] as $row) {
            self::assertIsArray($row);
            $rows[] = [(int) $row['chapter'], (int) $row['verse'], $row['verseequiv'] ?? 'missing'];
        }
        return $rows;
    }

    public function testKeywordLegReportsPrintedLabel(): void
    {
        $rows = $this->labels(['query' => 'labelmark', 'version' => 'TEST3', 'semantic_weight' => '0']);
        sort($rows);
        self::assertSame([[10, 1, '10:1a'], [12, 1, '12:1']], $rows);
    }

    public function testSemanticLegReportsPrintedLabel(): void
    {
        $rows = $this->labels(['query' => 'labelmark', 'version' => 'TEST3', 'model' => 'minilm', 'keyword_weight' => '0']);
        self::assertSame([12, 1, '12:1'], $rows[0]);
    }
}
