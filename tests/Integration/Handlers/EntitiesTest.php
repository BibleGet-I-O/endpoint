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
        // How TEST1 prints Genesis 1:3: labelled "L3", under chapter 2's heading (as NABRE Wis 12:1 under 11).
        $pdo->exec('UPDATE "TEST1" SET verse_label = \'L3\', printed_chapter = 2 WHERE book = 1 AND chapter = 1 AND verse = 3');
        $pdo->exec("INSERT INTO btu_link (edition, book, ord, ref, units, kind, status) VALUES
            ('TEST1', 1, 0, '1:1', ARRAY['btu:gn.1.1'], 'same', 'rule'),
            ('TEST1', 1, 1, '1:2', ARRAY['btu:gn.1.2'], 'same', 'reviewed'),
            ('TEST1', 1, 2, '1:3', ARRAY['btu:gn.1.3'], 'corresponding', 'rule'),
            ('TEST1', 1, 3, '1:4', ARRAY['btu:gn.1.3'], 'same', 'rule'),
            ('TEST1', 1, 4, '1:5', ARRAY['btu:gn.1.4'], 'same', 'draft'),
            ('TEST1', 19, 0, '1:1a@GREEK', ARRAY['btu:est.1.1a'], 'same', 'rule'),
            ('TEST1', 19, 1, '2:5@HEBREW', ARRAY['btu:est.2.5'], 'same', 'rule'),
            ('TEST1', 19, 2, '2:5@GREEK', ARRAY['btu:est.2.5'], 'same', 'rule'),
            ('TEST1', 19, 5, '8:2@HEBREW', ARRAY['btu:est.8.2'], 'same', 'rule'),
            ('TEST1', 19, 10, '2:7@GREEK', ARRAY['btu:est.2.7'], 'same', 'rule'),
            ('TEST1', 20, 0, '1:1', ARRAY['btu:iob.1.1'], 'same', 'rule')");
        $e = static fn (string $id, string $type, array $attested, array $labels = []): array => [
            'id'          => $id,
            'type'        => $type,
            'kind'        => 'individual',
            'names'       => ['la' => 'N', 'it' => 'N', 'en' => 'N'],
            'description' => $id === 'bpers:a' ? [] : ['en' => 'd'],
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
            $e('bpers:f', 'person', ['btu:est.2.5']),
            $e('bpers:g', 'person', ['btu:est.2.7', 'btu:est.8.2']),
            $e('bpers:h', 'person', ['btu:est.2.7', 'btu:est.8.2', 'btu:iob.1.1']),
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
        $this->getConnection()->exec('UPDATE "TEST1" SET verse_label = NULL, printed_chapter = NULL WHERE book = 1 AND chapter = 1 AND verse = 3');
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

    /** An empty map of names or descriptions is still a JSON object, as documented. */
    public function testSendsAnEmptyDescriptionAsAnObject(): void
    {
        $handler = new MetadataHandler(new Psr17Factory(), ['entities']);
        $handler->setAllowedRequestMethods([RequestMethod::GET])->setAllowedAcceptHeaders([AcceptHeader::JSON]);
        $response = $handler->handle(( new ServerRequest('GET', '/v3/metadata/entities', ['Accept' => 'application/json']) )->withQueryParams(['version' => 'TEST1', 'type' => 'person']));
        $body     = json_decode((string) $response->getBody()); // objects stay objects
        self::assertIsObject($body);
        self::assertIsArray($body->entries);
        $a = array_values(array_filter($body->entries, static fn ($e): bool => $e->id === 'bpers:a'))[0];
        self::assertInstanceOf(\stdClass::class, $a->description);
        self::assertSame([], get_object_vars($a->description));
        self::assertIsArray($a->refs);
    }

    public function testListsEveryVerseOfAUnitLinkedToTwo(): void
    {
        self::assertSame([[1, 1, 3, null, null, 'L3', 2], self::ref(1, 4)], self::byId($this->index(['version' => 'TEST1', 'type' => 'person']))['bpers:b']['refs']);
    }

    /** A verse the edition prints with a label of its own, or under another chapter, carries both after the five fields. */
    public function testCarriesThePrintedLabelAndChapter(): void
    {
        $refs = self::byId($this->index(['version' => 'TEST1', 'type' => 'person']))['bpers:b']['refs'];
        self::assertSame([1, 1, 3, null, null, 'L3', 2], $refs[0]);
        self::assertCount(5, $refs[1]);
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
        self::assertSame([self::ref(1, 1, 19, 'a', 'GREEK')], self::byId($this->index(['version' => 'TEST1', 'type' => 'person']))['bpers:e']['refs']);
    }

    /** Editions with both texts of Esther link a verse of each to one unit: the verse is listed once, in the Greek text. */
    public function testListsAnEstherVerseOnceWhenBothTextsHaveIt(): void
    {
        self::assertSame([self::ref(2, 5, 19, null, 'GREEK')], self::byId($this->index(['version' => 'TEST1', 'type' => 'person']))['bpers:f']['refs']);
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

    /** The route has no book parameter: one sent is ignored, not rejected as a bad book number. */
    public function testIgnoresABookParameter(): void
    {
        self::assertArrayHasKey('bpers:f', self::byId($this->index(['version' => 'TEST1', 'type' => 'person', 'book' => 'abc'])));
    }

    /** An entry's Esther verses from both texts (one only in the Greek, one only in the Hebrew) go in chapter order. */
    public function testOrdersEstherVersesOfBothTextsByChapter(): void
    {
        self::assertSame(
            [self::ref(2, 7, 19, null, 'GREEK'), self::ref(8, 2, 19, null, 'HEBREW')],
            self::byId($this->index(['version' => 'TEST1', 'type' => 'person']))['bpers:g']['refs']
        );
    }

    /** Only the Esther verses are put in chapter order: another book's verses keep their place after them. */
    public function testOrdersOnlyTheEstherVersesAmongOtherBooks(): void
    {
        self::assertSame(
            [self::ref(2, 7, 19, null, 'GREEK'), self::ref(8, 2, 19, null, 'HEBREW'), self::ref(1, 1, 20)],
            self::byId($this->index(['version' => 'TEST1', 'type' => 'person']))['bpers:h']['refs']
        );
    }
}
