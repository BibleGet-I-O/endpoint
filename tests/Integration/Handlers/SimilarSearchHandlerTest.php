<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\SimilarSearchHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

class SimilarSearchHandlerTest extends DatabaseTestCase
{
    private function createHandler(): SimilarSearchHandler
    {
        $handler = new SimilarSearchHandler(new Psr17Factory());
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        return $handler;
    }

    protected function setUp(): void
    {
        parent::setUp();
        SimilarSearchHandler::resetCache();

        $pdo = $this->getConnection();
        try {
            $pdo->query('SELECT 1 FROM "TEST1" LIMIT 1');
        } catch (\PDOException $e) {
            self::markTestSkipped('Test table TEST1 not available: ' . $e->getMessage());
        }

        // Seed embeddings on a few test verses
        $v1 = '[' . implode(',', array_fill(0, 384, 0.1)) . ']';
        $v2 = '[' . implode(',', array_fill(0, 384, 0.2)) . ']';
        $v3 = '[' . implode(',', array_fill(0, 384, -0.1)) . ']';
        $pdo->exec('UPDATE "TEST1" SET embedding = \'' . $v1 . '\' WHERE book = 1 AND chapter = 1 AND verse = 1');
        $pdo->exec('UPDATE "TEST1" SET embedding = \'' . $v2 . '\' WHERE book = 1 AND chapter = 1 AND verse = 2');
        $pdo->exec('UPDATE "TEST1" SET embedding = \'' . $v3 . '\' WHERE book = 1 AND chapter = 1 AND verse = 3');
    }

    // ── Validation ──────────────────────────────────────────

    public function testMissingReferenceThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('reference');
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['version' => 'TEST1']);
        $handler->handle($request);
    }

    public function testMissingVersionThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('version');
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Gen1:1']);
        $handler->handle($request);
    }

    public function testInvalidReferenceFormatThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reference format');
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'invalid', 'version' => 'TEST1']);
        $handler->handle($request);
    }

    public function testUnknownBookThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown book');
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Xyz1:1', 'version' => 'TEST1']);
        $handler->handle($request);
    }

    public function testVerseWithoutEmbeddingThrows(): void
    {
        // Verse 10 should exist but have no embedding
        $this->expectException(NotFoundException::class);
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Gen1:10', 'version' => 'TEST1']);
        $handler->handle($request);
    }

    public function testOptionsReturns200(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('OPTIONS', '/v3/search/similar'));
        self::assertSame(200, $response->getStatusCode());
    }

    // ── Successful search ───────────────────────────────────

    public function testSimilarSearchReturnsResults(): void
    {
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Gen1:1', 'version' => 'TEST1', 'limit' => '5']);

        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('results', $body);
        self::assertNotEmpty($body['results']);
    }

    public function testSimilarSearchExcludesSourceVerse(): void
    {
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Gen1:1', 'version' => 'TEST1']);

        $response = $handler->handle($request);
        $body     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        // The source verse (Gen 1:1, book=1, chapter=1, verse=1) should not appear
        foreach ($body['results'] as $result) {
            $isSame = ( $result['chapter'] === 1 && $result['verse'] === 1 && $result['univbooknum'] == 1 );
            self::assertFalse($isSame, 'Source verse should be excluded from similar results');
        }
    }

    public function testSimilarSearchResultHasScoreField(): void
    {
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Gen1:1', 'version' => 'TEST1']);

        $response = $handler->handle($request);
        $body     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);

        $first = $body['results'][0];
        self::assertArrayHasKey('score', $first);
        self::assertArrayNotHasKey('similarity', $first, 'similarity field renamed to score (issue #110)');
        self::assertIsNumeric($first['score']);
        self::assertArrayHasKey('canonical_order', $first);
        self::assertIsInt($first['canonical_order']);
        self::assertGreaterThanOrEqual(1, $first['canonical_order']);
        self::assertArrayNotHasKey('verseID', $first, 'verseID must never cross the API boundary');
    }

    public function testInfoContainsEndpointVersion(): void
    {
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Gen1:1', 'version' => 'TEST1']);

        $response = $handler->handle($request);
        $body     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('3.0', $body['info']['ENDPOINT_VERSION']);
    }

    // ── Multi-version `version=A,B` (issue #110) ────────────

    public function testMultiVersionSearchesAllListedVersions(): void
    {
        // Seed a couple of TEST2 verses with embeddings so the query can
        // return non-empty results across both versions.
        $pdo = $this->getConnection();
        $v1  = '[' . implode(',', array_fill(0, 384, 0.15)) . ']';
        $v2  = '[' . implode(',', array_fill(0, 384, -0.05)) . ']';
        $pdo->exec('UPDATE "TEST2" SET embedding = \'' . $v1 . '\' WHERE book = 1 AND chapter = 1 AND verse = 2');
        $pdo->exec('UPDATE "TEST2" SET embedding = \'' . $v2 . '\' WHERE book = 1 AND chapter = 1 AND verse = 3');

        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Gen1:1', 'version' => 'TEST1,TEST2', 'limit' => '20']);

        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);

        $versionsSeen = array_unique(array_column($body['results'], 'version'));
        sort($versionsSeen);
        self::assertSame(['TEST1', 'TEST2'], $versionsSeen);

        // Per-version partition: each version's first row gets canonical_order=1
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

    public function testCrossversionParamHasNoEffect(): void
    {
        // crossversion=true was removed (issue #110). Passing it should not
        // expand the search beyond the explicitly-listed version.
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams([
                'reference'    => 'Gen1:1',
                'version'      => 'TEST1',
                'crossversion' => 'true',
                'limit'        => '20',
            ]);

        $response = $handler->handle($request);
        $body     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        $versionsSeen = array_unique(array_column($body['results'], 'version'));
        self::assertSame(['TEST1'], array_values($versionsSeen));
    }
}
