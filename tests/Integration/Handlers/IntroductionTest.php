<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Handlers\MetadataHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Api\Http\Exception\ForbiddenException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/** /v3/metadata/introduction: books' and general introductions, on synthetic rows. */
final class IntroductionTest extends DatabaseTestCase
{
    private const BLOCKS = '[{"type": "heading", "level": 1, "text": "Head", "refs": []},'
        . ' {"type": "paragraph", "text": "See <i>Gn</i> 1:1.", "refs": [{"start": {"book": 1, "chapter": 1, "verse": 1, "segment": null, "printed_chapter": null}, "end": null, "span": [4, 16]}]}]';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        foreach (['069-book-outline.sql', '071-book-introductions.sql', '071-book-introductions.sql'] as $file) {
            $pdo->exec((string) file_get_contents(__DIR__ . '/../../../migrations/' . $file)); // 071 twice: idempotent
        }
        $this->clean();
        $stmt = $pdo->prepare('INSERT INTO book_introduction (edition, id, ord, title, books, general, blocks) VALUES (?, ?, ?, ?, ?::int[], ?, ?::jsonb)');
        foreach (
            [
                ['TEST1', 'history', 1, 'THE HISTORY', '{9,10,11}', 'true', '[]'],
                ['TEST1', '9', 2, 'THE BOOKS OF SAMUEL', '{9,10}', 'false', self::BLOCKS],
                ['TEST1', 'pairs', 3, 'PAIRS', '{10}', 'true', '[]'],
                ['TEST2', '9', 1, 'ANOTHER EDITION', '{9}', 'false', '[]'],
            ] as $row
        ) {
            $stmt->execute($row);
        }
        $pdo->exec("INSERT INTO book_outline (edition, book, ord, level, title, chapter, verse) VALUES ('TEST1', 10, 1, 1, 'A title', 1, 1)");
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        $this->getConnection()->exec("DELETE FROM book_introduction WHERE edition IN ('TEST1', 'TEST2')");
        $this->getConnection()->exec("DELETE FROM book_outline WHERE edition IN ('TEST1', 'TEST2')");
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function get(string $what, array $params, ?string $scope = 'reader', string $accept = 'application/json'): array
    {
        $handler = new MetadataHandler(new Psr17Factory(), [$what]);
        $handler->setAllowedRequestMethods([RequestMethod::GET])->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        $request = ( new ServerRequest('GET', '/v3/metadata/' . $what, ['Accept' => $accept]) )->withQueryParams($params);
        if ($scope !== null) {
            $request = $request->withAttribute('credential', new Credential(CredentialType::Project, 'k1', $scope, null, 'a1', null, null, true));
        }
        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }

    public function testABooksIntroductionWithTheGeneralOnesCoveringIt(): void
    {
        $body = $this->get('introduction', ['version' => 'test1', 'book' => '10']);
        self::assertSame(['version' => 'TEST1', 'book' => 10], array_slice($body, 0, 2));
        // JSONB keeps its own key order: compare the blocks as values.
        self::assertEquals([
            'id'     => '9',
            'title'  => 'THE BOOKS OF SAMUEL',
            'books'  => [9, 10],
            'blocks' => json_decode(self::BLOCKS, true),
        ], $body['introduction']);
        self::assertSame([
            ['id' => 'history', 'title' => 'THE HISTORY', 'books' => [9, 10, 11]],
            ['id' => 'pairs', 'title' => 'PAIRS', 'books' => [10]],
        ], $body['general']);
    }

    public function testABookWithoutAnIntroduction(): void
    {
        $body = $this->get('introduction', ['version' => 'TEST1', 'book' => '73']);
        self::assertNull($body['introduction']);
        self::assertSame([], $body['general']);
    }

    public function testAGeneralIntroductionById(): void
    {
        $body = $this->get('introduction', ['version' => 'TEST1', 'id' => 'history']);
        self::assertSame('TEST1', $body['version']);
        self::assertArrayNotHasKey('general', $body);
        self::assertSame(['introduction' => ['id' => 'history', 'title' => 'THE HISTORY', 'books' => [9, 10, 11], 'blocks' => []]], array_intersect_key($body, ['introduction' => 1]));
    }

    public function testAnUnknownIdIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->get('introduction', ['version' => 'TEST1', 'id' => 'nope']);
    }

    /** @return list<array{0: ?string}> */
    public static function notReader(): array
    {
        return [[null], ['read']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notReader')]
    public function testOnlyTheReaderKey(?string $scope): void
    {
        $this->expectException(ForbiddenException::class);
        $this->get('introduction', ['version' => 'TEST1', 'book' => '9'], $scope);
    }

    /** @return list<array{0: array<string, string>, 1: string}> */
    public static function badRequests(): array
    {
        return [
            [['version' => 'NOPE', 'book' => '9'], 'application/json'],
            [['version' => 'TEST1', 'book' => '74'], 'application/json'],
            [['version' => 'TEST1'], 'application/json'],
            [['version' => 'TEST1', 'book' => '9', 'id' => 'history'], 'application/json'],
            [['version' => 'TEST1', 'id' => 'Bad Id!'], 'application/json'],
            [['version' => 'TEST1', 'book' => '9', 'return' => 'xml'], 'application/json'],
            [['version' => 'TEST1', 'book' => '9'], 'text/html'],
        ];
    }

    /** @param array<string, string> $params */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRequests')]
    public function testBadRequestsAre400(array $params, string $accept): void
    {
        $this->expectException(BadRequestException::class);
        $this->get('introduction', $params, 'reader', $accept);
    }

    public function testTheOutlineNamesTheIntroductionToTheReaderKeyOnly(): void
    {
        self::assertSame(['id' => '9', 'title' => 'THE BOOKS OF SAMUEL'], $this->get('outline', ['version' => 'TEST1', 'book' => '10'])['introduction']);
        self::assertNull($this->get('outline', ['version' => 'TEST1', 'book' => '73'])['introduction']);
        self::assertArrayNotHasKey('introduction', $this->get('outline', ['version' => 'TEST1', 'book' => '10'], 'read'));
        self::assertArrayNotHasKey('introduction', $this->get('outline', ['version' => 'TEST1', 'book' => '10'], null));
    }

    public function testBlocksMustBeAnArrayAndBooksNotEmpty(): void
    {
        $pdo = $this->getConnection();
        foreach (["'{1}', false, '{}'", "'{}', false, '[]'"] as $bad) {
            try {
                $pdo->exec("INSERT INTO book_introduction (edition, id, ord, title, books, general, blocks) VALUES ('TEST1', 'bad', 9, 'X', $bad)");
                self::fail("accepted $bad");
            } catch (\PDOException $e) {
                self::assertSame('23514', $e->getCode());
            }
        }
    }
}
