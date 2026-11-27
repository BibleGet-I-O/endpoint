<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\MetadataHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Api\Repositories\EntityRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/** /v3/metadata/entities: an edition's index of persons, places or events, on synthetic entries and links. */
final class EntitiesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../../migrations/053-btu-review-store.sql'));
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../../migrations/072-cbppedr.sql'));
        $this->clean();
        $pdo->exec("INSERT INTO btu_link (edition, book, ord, ref, units, kind, status) VALUES
            ('TEST1', 1, 0, '1:1', ARRAY['btu:gn.1.1'], 'same', 'rule'),
            ('TEST1', 1, 1, '1:2', ARRAY['btu:gn.1.2'], 'same', 'reviewed'),
            ('TEST1', 1, 2, '1:3', ARRAY['btu:gn.1.3'], 'corresponding', 'rule'),
            ('TEST1', 1, 3, '1:4', ARRAY['btu:gn.1.3'], 'same', 'rule'),
            ('TEST1', 1, 4, '1:5', ARRAY['btu:gn.1.4'], 'same', 'draft'),
            ('TEST1', 17, 0, '1:1a@GREEK', ARRAY['btu:est.1.1a'], 'same', 'rule')");
        $e = static fn (string $id, string $type, array $attested, array $labels = []): array => [
            'id'          => $id,
            'type'        => $type,
            'kind'        => 'individual',
            'names'       => ['la' => 'N', 'it' => 'N', 'en' => 'N'],
            'description' => ['en' => 'd'],
            'qid'         => 'Q1',
            'qid_status'  => 'auto',
            'uncertain'   => $id === 'bpers:b',
            'attested'    => $attested,
            'labels'      => $labels,
        ];
        ( new EntityRepository($pdo) )->replaceAll([
            $e('bpers:a', 'person', ['btu:gn.1.2', 'btu:gn.1.1'], ['btu:gn.1.2' => ['TEST1']]),
            $e('bpers:b', 'person', ['btu:gn.1.3']),
            $e('bpers:c', 'person', ['btu:gn.1.4']),
            $e('bpers:d', 'person', ['btu:gn.1.1', 'btu:gn.1.2'], ['btu:gn.1.2' => ['NVBSE']]),
            $e('bpers:e', 'person', ['btu:est.1.1a']),
            $e('bplace:p', 'place', ['btu:gn.1.1']),
        ], 'c1');
    }

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->clean();
        }
        parent::tearDown();
    }

    private function clean(): void
    {
        $this->getConnection()->exec('TRUNCATE bpe_entry, bpe_attested, bpe_label, bpe_release, btu_link RESTART IDENTITY CASCADE');
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function index(array $params): array
    {
        $handler = new MetadataHandler(new Psr17Factory(), ['entities']);
        $handler->setAllowedRequestMethods([RequestMethod::GET])->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        $response = $handler->handle(( new ServerRequest('GET', '/v3/metadata/entities', ['Accept' => 'application/json']) )->withQueryParams($params));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, array<string, mixed>>
     */
    private static function byId(array $body): array
    {
        self::assertIsArray($body['entries']);
        /** @var list<array<string, mixed>> $entries */
        $entries = $body['entries'];
        return array_column($entries, null, 'id');
    }

    /** @return array{0: int, 1: int, 2: int, 3: ?string, 4: ?string} A ref as the route sends it: [book, chapter, verse, segment, tradition]. */
    private static function ref(int $chapter, int $verse, int $book = 1, ?string $segment = null, ?string $tradition = null): array
    {
        return [$book, $chapter, $verse, $segment, $tradition];
    }

    public function testResolvesUnitsToTheEditionsVerses(): void
    {
        $body = $this->index(['version' => 'test1', 'type' => 'person']);
        self::assertSame('TEST1', $body['edition']);
        self::assertSame('person', $body['type']);
        self::assertIsArray($body['release']);
        self::assertSame('c1', $body['release']['commit']);
        $a = self::byId($body)['bpers:a'];
        self::assertSame([self::ref(1, 1), self::ref(1, 2)], $a['refs']);
        self::assertEquals(['la' => 'N', 'it' => 'N', 'en' => 'N'], $a['names']); // jsonb does not keep key order
        self::assertSame('auto', $a['qid_status']);
        self::assertFalse($a['uncertain']);
        self::assertTrue(self::byId($body)['bpers:b']['uncertain']);
        self::assertArrayNotHasKey('bplace:p', self::byId($body));
    }

    public function testListsEveryVerseOfAUnitLinkedToTwo(): void
    {
        self::assertSame([self::ref(1, 3), self::ref(1, 4)], self::byId($this->index(['version' => 'TEST1', 'type' => 'person']))['bpers:b']['refs']);
    }

    public function testSkipsUnitsWithOnlyDraftLinks(): void
    {
        self::assertArrayNotHasKey('bpers:c', self::byId($this->index(['version' => 'TEST1', 'type' => 'person'])));
    }

    public function testLeavesOutALabelledUnitForAnEditionTheLabelDoesNotName(): void
    {
        self::assertSame([self::ref(1, 1)], self::byId($this->index(['version' => 'TEST1', 'type' => 'person']))['bpers:d']['refs']);
    }

    public function testParsesTheTraditionOfEstherRefs(): void
    {
        self::assertSame([self::ref(1, 1, 17, 'a', 'GREEK')], self::byId($this->index(['version' => 'TEST1', 'type' => 'person']))['bpers:e']['refs']);
    }

    public function testAnswersEmptyForAnEditionWithoutLinks(): void
    {
        self::assertSame([], $this->index(['version' => 'TEST2', 'type' => 'place'])['entries']);
    }

    public function testRejectsAnUnknownType(): void
    {
        $this->expectException(BadRequestException::class);
        $this->index(['version' => 'TEST1', 'type' => 'angel']);
    }
}
