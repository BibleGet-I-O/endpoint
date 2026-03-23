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
use Nyholm\Psr7\ServerRequest;

class SimilarSearchHandlerTest extends DatabaseTestCase
{
    private function createHandler(): SimilarSearchHandler
    {
        $handler = new SimilarSearchHandler();
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        return $handler;
    }

    protected function setUp(): void
    {
        parent::setUp();
        SimilarSearchHandler::resetCache();

        // Seed embeddings on a few test verses
        $pdo = $this->getConnection();
        $v1  = '[' . implode(',', array_fill(0, 384, 0.1)) . ']';
        $v2  = '[' . implode(',', array_fill(0, 384, 0.2)) . ']';
        $v3  = '[' . implode(',', array_fill(0, 384, -0.1)) . ']';
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

    public function testSimilarSearchResultHasSimilarityField(): void
    {
        $handler = $this->createHandler();
        $request = ( new ServerRequest('GET', '/v3/search/similar') )
            ->withQueryParams(['reference' => 'Gen1:1', 'version' => 'TEST1']);

        $response = $handler->handle($request);
        $body     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertNotEmpty($body['results']);

        $first = $body['results'][0];
        self::assertArrayHasKey('similarity', $first);
        self::assertIsNumeric($first['similarity']);
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
}
