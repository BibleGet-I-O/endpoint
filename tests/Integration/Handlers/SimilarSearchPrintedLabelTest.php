<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\SimilarSearchHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/**
 * /search/similar finds its starting verse by the number the edition prints
 * (fixture TEST3: Wis 12:1 stored after 11:26, Job 10:1 split across 9:35
 * and 10:1), and leaves every row of that verse out of the results.
 */
final class SimilarSearchPrintedLabelTest extends DatabaseTestCase
{
    private function createHandler(): SimilarSearchHandler
    {
        $handler = new SimilarSearchHandler(new Psr17Factory());
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        return $handler;
    }

    /**
     * A 384-dimension vector with the given leading components, zeros after.
     *
     * @param list<float> $head
     */
    private static function vec(array $head): string
    {
        return '[' . implode(',', array_pad($head, 384, 0.0)) . ']';
    }

    protected function setUp(): void
    {
        parent::setUp();
        SimilarSearchHandler::resetCache();

        $pdo = $this->getConnection();
        try {
            $pdo->query('SELECT 1 FROM "TEST3" LIMIT 1');
        } catch (\PDOException $e) {
            self::markTestSkipped('Test table TEST3 not available: ' . $e->getMessage());
        }

        $set = static function (string $where, string $vector) use ($pdo): void {
            $pdo->exec('UPDATE "TEST3" SET embedding = \'' . $vector . '\' WHERE ' . $where);
        };
        $set('true', self::vec([0.0, 0.0, 0.0, 0.0, 1.0]));
        // The two parts of Job 10:1, and a row equal to their average.
        $set("verse_label = '10:1'", self::vec([1.0, 0.0]));
        $set("chapter = 10 AND verse = 1 AND verse_label = ''", self::vec([0.0, 1.0]));
        $set('chapter = 9 AND verse = 34', self::vec([0.5, 0.5]));
        // Esther 1:1 in two texts; 1:2 GREEK is near the Greek 1:1, 1:2 HEBREW
        // equals the blend of the two 1:1 rows.
        $set("book = 19 AND verse = 1 AND text_tradition = 'HEBREW'", self::vec([0.0, 0.0, 0.0, 0.0, 0.0, 1.0]));
        $set("book = 19 AND verse = 1 AND text_tradition = 'GREEK'", self::vec([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0]));
        $set("book = 19 AND verse = 2 AND text_tradition = 'GREEK'", self::vec([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.9, 0.1]));
        $set("book = 19 AND verse = 2 AND text_tradition = 'HEBREW'", self::vec([0.0, 0.0, 0.0, 0.0, 0.0, 0.6, 0.6]));
        // Wis 12:1, and a row close to it.
        $set("verse_label = '12:1'", self::vec([0.0, 0.0, 1.0]));
        // Mk 9:44 is omitted (no text); give it Mk 9:43's embedding anyway, so a
        // search that forgot to exclude omitted rows would rank it first.
        $set('book = 48 AND chapter = 9 AND verse IN (43, 44)', self::vec([0.0, 0.0, 0.0, 1.0]));
        $set('chapter = 12 AND verse = 3', self::vec([0.0, 0.0, 0.9, 0.1]));
    }

    protected function tearDown(): void
    {
        // The embeddings set in setUp would otherwise stay in the shared test DB.
        try {
            $this->getConnection()->exec('UPDATE "TEST3" SET embedding = NULL');
        } catch (\Throwable) {
            // DB not available (test was skipped)
        }
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function similar(string $reference): array
    {
        $request  = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => $reference, 'version' => 'TEST3', 'model' => 'minilm', 'limit' => '20']);
        $response = $this->createHandler()->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['results']);
        /** @var list<array<string, mixed>> $results */
        $results = $body['results'];
        return $results;
    }

    public function testSimilarFromPrintedLabel(): void
    {
        $results = $this->similar('Wis12:1');
        self::assertNotEmpty($results);
        self::assertSame([12, 3], [$results[0]['chapter'], $results[0]['verse']]);
        foreach ($results as $r) {
            self::assertNotSame([12, 1], [$r['chapter'], $r['verse']], 'Wis 12:1 itself must be excluded');
        }
    }

    public function testOmittedRowsNeverReturned(): void
    {
        $results = $this->similar('Mk9:43');
        foreach ($results as $r) {
            self::assertNotSame([9, 44], [$r['chapter'], $r['verse']], 'an omitted verse must never be returned');
            self::assertFalse($r['omitted']);
        }
    }

    public function testOmittedSourceVerseIs404(): void
    {
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Mk9:44', 'version' => 'TEST3', 'model' => 'minilm']);
        try {
            $response = $this->createHandler()->handle($request);
            $status   = $response->getStatusCode();
            $detail   = (string) $response->getBody();
        } catch (\BibleGet\Api\Http\Exception\ApiException $e) {
            $status = $e->getCode();
            $detail = $e->getMessage();
        }
        self::assertSame(404, $status);
        self::assertStringContainsString('omitted in this edition', $detail);
    }

    public function testSimilarFromSplitVerseAveragesAndExcludesBoth(): void
    {
        $results = $this->similar('Job10:1');
        self::assertNotEmpty($results);
        // Job 9:34 holds exactly the average of the two parts of 10:1.
        self::assertSame([9, 34], [$results[0]['chapter'], $results[0]['verse']]);
        self::assertEqualsWithDelta(1.0, $results[0]['score'], 0.0001);
        foreach ($results as $r) {
            self::assertNotSame([10, 1], [$r['chapter'], $r['verse']], 'both parts of Job 10:1 must be excluded');
        }
    }

    public function testSimilarFromEstherStartsFromTheGreekTextOnly(): void
    {
        $results = $this->similar('Est1:1');
        self::assertNotEmpty($results);
        self::assertSame('Est 1:2 greek placeholder', $results[0]['text']);
    }

    public function testSimilarUnknownVerseIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->similar('Wis12:9');
    }
}
