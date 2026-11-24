<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Handlers\MetadataHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/** /v3/metadata/outline: a book's titles with their levels, on synthetic rows. */
final class OutlineTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        $sql = (string) file_get_contents(__DIR__ . '/../../../migrations/069-book-outline.sql');
        $pdo->exec($sql);
        $pdo->exec($sql); // idempotent
        $pdo->exec("DELETE FROM book_outline WHERE edition IN ('TEST1', 'TEST2')");
        $pdo->exec("INSERT INTO book_outline (edition, book, ord, level, title, chapter, verse, verse_segment, printed_chapter, reader_only) VALUES
            ('TEST1', 1, 2, 3, 'A section', 1, 2, 'a', 2, false),
            ('TEST1', 1, 1, 1, 'A division', 1, 1, NULL, NULL, false),
            ('TEST1', 1, 3, 2, 'From the commentary', 1, 3, NULL, NULL, true),
            ('TEST1', 2, 1, 1, 'Another book', 1, 1, NULL, NULL, false),
            ('TEST2', 1, 1, 1, 'Another edition', 1, 1, NULL, NULL, false)");
    }

    protected function tearDown(): void
    {
        $this->getConnection()->exec("DELETE FROM book_outline WHERE edition IN ('TEST1', 'TEST2')");
        parent::tearDown();
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function outline(array $params, ?string $scope = null, string $accept = 'application/json'): array
    {
        $handler = new MetadataHandler(new Psr17Factory(), ['outline']);
        $handler->setAllowedRequestMethods([RequestMethod::GET])->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        $request = ( new ServerRequest('GET', '/v3/metadata/outline', ['Accept' => $accept]) )->withQueryParams($params);
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

    public function testABooksTitlesInOrderWithTheirLevels(): void
    {
        $body = $this->outline(['version' => 'test1', 'book' => '1']);
        self::assertSame('TEST1', $body['version']);
        self::assertSame(1, $body['book']);
        self::assertSame([
            ['level' => 1, 'title' => 'A division', 'chapter' => 1, 'verse' => 1, 'segment' => null, 'printed_chapter' => null],
            ['level' => 3, 'title' => 'A section', 'chapter' => 1, 'verse' => 2, 'segment' => 'a', 'printed_chapter' => 2],
        ], $body['titles']);
    }

    public function testCommentaryTitlesAreForTheReaderKeyOnly(): void
    {
        $titles = static fn (array $b): array => array_column(is_array($b['titles'] ?? null) ? $b['titles'] : [], 'title');
        self::assertSame(['A division', 'A section', 'From the commentary'], $titles($this->outline(['version' => 'TEST1', 'book' => '1'], 'reader')));
        self::assertSame(['A division', 'A section'], $titles($this->outline(['version' => 'TEST1', 'book' => '1'], 'read')));
    }

    public function testABookWithoutTitlesIsAnEmptyList(): void
    {
        self::assertSame([], $this->outline(['version' => 'TEST1', 'book' => '73'])['titles']);
    }

    /** @return list<array{0: array<string, string>, 1: string}> */
    public static function badRequests(): array
    {
        return [
            [['version' => 'NOPE', 'book' => '1'], 'application/json'],
            [['version' => 'TEST1', 'book' => '0'], 'application/json'],
            [['version' => 'TEST1', 'book' => '1x'], 'application/json'],
            [['version' => 'TEST1'], 'application/json'],
            [['version' => 'TEST1', 'book' => '1', 'return' => 'xml'], 'application/json'],
            [['version' => 'TEST1', 'book' => '1'], 'text/html'],
        ];
    }

    /** @param array<string, string> $params */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRequests')]
    public function testBadRequestsAre400(array $params, string $accept): void
    {
        $this->expectException(BadRequestException::class);
        $this->outline($params, null, $accept);
    }
}
