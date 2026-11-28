<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\QuoteHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Monolog\Handler\TestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/**
 * /v3/quote?mentions=1 (mentions-and-cards spec, Endpoint): the CBPPEDR mentions of the rows given and the cards of
 * the entries they name, on synthetic entries and mentions of the test editions (TEST1 stands for the Nova Vulgata).
 */
final class QuoteMentionsTest extends DatabaseTestCase
{
    private const string MIGRATIONS = __DIR__ . '/../../../migrations/';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        foreach (['053-btu-review-store.sql', '072-cbppedr.sql', '073-cbppedr-mentions.sql'] as $file) {
            $pdo->exec((string) file_get_contents(self::MIGRATIONS . $file));
        }
        $this->clean();
        foreach (['bpers:petrus', 'bpers:marcus', 'bpers:paulus', 'bpers:ioannes'] as $id) {
            $this->entry($id);
        }
        $this->entry('bplace:roma', 'place', ['la' => 'Roma', 'en' => 'Rome'], [], 'reviewed');
        $this->entry('bplace:via', 'place');
        $pdo->exec("INSERT INTO bpe_detail (entry, image, wikipedia, coords) VALUES
            ('bplace:roma', '{\"file\": \"Roma.jpg\", \"license\": \"CC0\", \"author\": 7}', '{\"en\": \"Rome\"}', '{\"lat\": 41.9, \"lon\": 12.5}')");
        $pdo->exec("INSERT INTO bpe_verse_count (edition, entry, verses) VALUES ('TEST1', 'bplace:roma', 7), ('TEST1', 'bpers:petrus', 3)");
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
        $this->getConnection()->exec('TRUNCATE bpe_mention, bpe_detail, bpe_verse_count, bpe_entry, bpe_attested, bpe_label, bpe_release RESTART IDENTITY CASCADE');
    }

    /**
     * @param array<string, string> $names
     * @param array<string, string> $description
     */
    private function entry(string $id, string $type = 'person', array $names = ['la' => 'N'], array $description = ['en' => 'd'], ?string $qidStatus = null): void
    {
        $this->getConnection()->prepare(
            'INSERT INTO bpe_entry (id, type, kind, names, description, qid, qid_status, uncertain) VALUES (?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, false)'
        )->execute([$id, $type, 'individual', json_encode((object) $names), json_encode((object) $description), $qidStatus === null ? null : 'Q220', $qidStatus]);
    }

    /**
     * A mention on a row of $edition, hashed against the row's current text_plain and text unless $hash or
     * $textHash is given.
     *
     * @param ?list<string> $candidates
     */
    private function mention(string $edition, int $chapter, int $verse, int $start, int $end, ?string $entry, ?array $candidates = null, ?string $segment = null, ?string $tradition = null, ?string $hash = null, ?string $textHash = null): void
    {
        $pdo = $this->getConnection();
        $row = $pdo->prepare(
            "SELECT text, text_plain FROM \"$edition\" WHERE book = 1 AND chapter = ? AND verse = ? AND verse_segment IS NULL AND text_tradition IS NULL"
        );
        $row->execute([$chapter, $verse]);
        $texts = $row->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($texts);
        $pdo->prepare(
            "INSERT INTO bpe_mention (edition, book, chapter, verse, segment, tradition, unit, plain_hash, text_hash, start_cp, end_cp, entry, candidates)
             VALUES (?, 1, ?, ?, ?, ?, 'btu:gn', ?, ?, ?, ?, ?, (SELECT array_agg(c) FROM json_array_elements_text(?::json) AS c))"
        )->execute([
            $edition,
            $chapter,
            $verse,
            $segment,
            $tradition,
            $hash ?? hash('sha256', (string) $texts['text_plain']),
            $textHash ?? hash('sha256', (string) $texts['text']),
            $start,
            $end,
            $entry,
            $candidates === null ? null : json_encode($candidates),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    private function raw(array $params, string $accept = 'application/json'): string
    {
        $handler = new QuoteHandler(new Psr17Factory());
        $handler->setAllowedRequestMethods([RequestMethod::GET])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        $response = $handler->handle(( new ServerRequest('GET', '/v3/quote', ['Accept' => $accept]) )->withQueryParams($params));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return (string) $response->getBody();
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function quote(array $params): array
    {
        $body = json_decode($this->raw($params), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private static function rows(array $body): array
    {
        $rows = $body['results'] ?? null;
        self::assertIsArray($rows);
        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function entities(array $body): array
    {
        self::assertArrayHasKey('entities', $body);
        self::assertIsArray($body['entities']);
        /** @var array<string, mixed> $entities */
        $entities = $body['entities'];
        return $entities;
    }

    public function testAddsMentionsToNvbseRows(): void
    {
        $this->mention('TEST1', 1, 1, 10, 15, 'bpers:petrus');
        $this->mention('TEST1', 1, 1, 0, 20, 'bplace:roma');
        $this->mention('TEST1', 1, 1, 10, 12, 'bpers:marcus');
        $this->mention('TEST1', 1, 2, 4, 9, 'bpers:paulus');
        // Same verse numbers, other identities: a lettered sub-verse, Esther's Greek text. Never on 1:1.
        $this->mention('TEST1', 1, 1, 1, 2, 'bplace:via', null, 'a', null, str_repeat('0', 64));
        $this->mention('TEST1', 1, 1, 1, 2, 'bplace:via', null, null, 'GREEK', str_repeat('0', 64));

        $body = $this->quote(['query' => 'Gen1:1-3', 'version' => 'TEST1', 'mentions' => '1']);
        $rows = self::rows($body);
        self::assertSame(
            [
                ['start' => 0, 'end' => 20, 'entry' => 'bplace:roma'],
                ['start' => 10, 'end' => 15, 'entry' => 'bpers:petrus'],
                ['start' => 10, 'end' => 12, 'entry' => 'bpers:marcus'],
            ],
            $rows[0]['mentions'],
            'ordered by start, outer before inner; the sub-verse and GREEK mentions stay off 1:1'
        );
        self::assertSame([['start' => 4, 'end' => 9, 'entry' => 'bpers:paulus']], $rows[1]['mentions']);
        self::assertSame([], $rows[2]['mentions'], 'a row of the edition without mentions gets an empty list');

        $entities = self::entities($body);
        self::assertEqualsCanonicalizing(['bplace:roma', 'bpers:petrus', 'bpers:marcus', 'bpers:paulus'], array_keys($entities));
        self::assertSame(
            [
                'type'        => 'place',
                'kind'        => 'individual',
                'names'       => ['en' => 'Rome', 'la' => 'Roma'],
                'description' => [],
                'uncertain'   => false,
                'verse_count' => 7,
                'image'       => ['file' => 'Roma.jpg', 'license' => 'CC0'],
                'wikipedia'   => ['en' => 'Rome'],
                'coords'      => ['lat' => 41.9, 'lon' => 12.5],
            ],
            $entities['bplace:roma']
        );
        self::assertSame(
            ['type' => 'person', 'kind' => 'individual', 'names' => ['la' => 'N'], 'description' => ['en' => 'd'], 'uncertain' => false, 'verse_count' => 0],
            $entities['bpers:marcus'],
            'no detail: no image, wikipedia or coords; no count: 0'
        );

        // Names and descriptions are JSON objects even when empty; `true` asks as `1` does.
        self::assertStringContainsString('"description":{}', $this->raw(['query' => 'Gen1:1', 'version' => 'TEST1', 'mentions' => 'true']));
    }

    public function testOtherEditionsGetNone(): void
    {
        $this->mention('TEST1', 1, 1, 0, 5, 'bpers:petrus');
        $rows      = self::rows($this->quote(['query' => 'Gen1:1', 'version' => 'TEST1,TEST2', 'mentions' => '1']));
        $byVersion = [];
        foreach ($rows as $r) {
            $byVersion[$r['version']] = $r;
        }
        self::assertSame([['start' => 0, 'end' => 5, 'entry' => 'bpers:petrus']], $byVersion['TEST1']['mentions']);
        self::assertArrayNotHasKey('mentions', $byVersion['TEST2'], 'an edition without mentions: rows unchanged');

        $body = $this->quote(['query' => 'Gen1:1', 'version' => 'TEST2', 'mentions' => '1']);
        self::assertSame([], self::entities($body), 'requested: entities present, even empty');
        self::assertStringContainsString('"entities":{}', $this->raw(['query' => 'Gen1:1', 'version' => 'TEST2', 'mentions' => '1']));
    }

    public function testSendsOnlyTheEntitiesOfTheRowsGiven(): void
    {
        // TEST2 is copyrighted: a visitor gets 30 of Genesis 3's 35 verses, and only their mentions and entities.
        for ($v = 1; $v <= 35; $v++) {
            $this->entry("bpers:v$v");
            $this->mention('TEST2', 3, $v, 0, 4, "bpers:v$v");
        }
        $body = $this->quote(['query' => 'Gen3', 'version' => 'TEST2', 'mentions' => '1']);
        $rows = self::rows($body);
        self::assertCount(30, $rows);
        foreach ($rows as $r) {
            self::assertSame([['start' => 0, 'end' => 4, 'entry' => 'bpers:v' . $r['verse']]], $r['mentions']);
        }
        self::assertEqualsCanonicalizing(array_map(static fn (int $v): string => "bpers:v$v", range(1, 30)), array_keys(self::entities($body)));
    }

    public function testDropsTheMentionsOfARowWhoseTextChanged(): void
    {
        $this->mention('TEST1', 1, 1, 0, 5, 'bpers:petrus');
        $this->mention('TEST1', 1, 2, 0, 5, 'bpers:paulus', null, null, null, str_repeat('ab', 32));
        $body = $this->quote(['query' => 'Gen1:1-2', 'version' => 'TEST1', 'mentions' => '1']);
        $rows = self::rows($body);
        self::assertSame([['start' => 0, 'end' => 5, 'entry' => 'bpers:petrus']], $rows[0]['mentions']);
        self::assertSame([], $rows[1]['mentions'], 'the text no longer hashes to plain_hash');
        self::assertSame(['bpers:petrus'], array_keys(self::entities($body)));
    }

    /** Positions count code points of the formatted text: a change of markup alone drops them too. */
    public function testDropsTheMentionsOfARowWhoseMarkupChanged(): void
    {
        $this->mention('TEST1', 1, 1, 0, 5, 'bpers:petrus');
        $text = (string) $this->getConnection()->query('SELECT text FROM "TEST1" WHERE book = 1 AND chapter = 1 AND verse = 1 AND verse_segment IS NULL AND text_tradition IS NULL')?->fetchColumn();
        // Measured against the text as it was, in italics: the plain text is the same, the markup is not.
        $this->mention('TEST1', 1, 2, 0, 5, 'bpers:paulus', null, null, null, null, hash('sha256', '<i>' . $text . '</i>'));
        $body = $this->quote(['query' => 'Gen1:1-2', 'version' => 'TEST1', 'mentions' => '1']);
        $rows = self::rows($body);
        self::assertSame([['start' => 0, 'end' => 5, 'entry' => 'bpers:petrus']], $rows[0]['mentions']);
        self::assertSame([], $rows[1]['mentions'], 'the formatted text no longer hashes to text_hash');
        self::assertSame(['bpers:petrus'], array_keys(self::entities($body)));
    }

    /** Images, Wikipedia titles and coordinates only for an entry whose Wikidata item was reviewed or adjudicated. */
    public function testNoDetailsWithoutAReviewedQid(): void
    {
        $this->entry('bplace:nulla', 'place');
        $this->entry('bplace:auto', 'place', ['la' => 'N'], ['en' => 'd'], 'auto');
        $this->getConnection()->exec("INSERT INTO bpe_detail (entry, image, wikipedia, coords) VALUES
            ('bplace:nulla', '{\"file\": \"N.jpg\", \"license\": \"CC0\"}', '{\"en\": \"N\"}', '{\"lat\": 1.5, \"lon\": 2.5}'),
            ('bplace:auto', '{\"file\": \"A.jpg\", \"license\": \"CC0\"}', '{\"en\": \"A\"}', '{\"lat\": 3.5, \"lon\": 4.5}')");
        $this->mention('TEST1', 1, 1, 0, 5, 'bplace:nulla');
        $this->mention('TEST1', 1, 1, 6, 9, 'bplace:auto');
        $entities = self::entities($this->quote(['query' => 'Gen1:1', 'version' => 'TEST1', 'mentions' => '1']));
        foreach (['bplace:nulla', 'bplace:auto'] as $id) {
            self::assertIsArray($entities[$id]);
            foreach (['image', 'wikipedia', 'coords'] as $field) {
                self::assertArrayNotHasKey($field, $entities[$id], "$id $field");
            }
        }
    }

    /** An image is sent only with its file and its license (EntityCard.image requires both). */
    public function testNoImageWithoutAFileAndALicense(): void
    {
        $this->entry('bplace:sine', 'place', ['la' => 'N'], ['en' => 'd'], 'reviewed');
        $this->entry('bplace:anon', 'place', ['la' => 'N'], ['en' => 'd'], 'adjudicated');
        $this->getConnection()->exec("INSERT INTO bpe_detail (entry, image, wikipedia, coords) VALUES
            ('bplace:sine', '{\"file\": \"S.jpg\", \"author\": \"A\"}', '{\"en\": \"S\"}', NULL),
            ('bplace:anon', '{\"license\": \"CC0\"}', NULL, NULL)");
        $this->mention('TEST1', 1, 1, 0, 5, 'bplace:sine');
        $this->mention('TEST1', 1, 1, 6, 9, 'bplace:anon');
        $entities = self::entities($this->quote(['query' => 'Gen1:1', 'version' => 'TEST1', 'mentions' => '1']));
        self::assertIsArray($entities['bplace:sine']);
        self::assertIsArray($entities['bplace:anon']);
        self::assertArrayNotHasKey('image', $entities['bplace:sine'], 'no license');
        self::assertSame(['en' => 'S'], $entities['bplace:sine']['wikipedia'], 'the rest of the details stay');
        self::assertArrayNotHasKey('image', $entities['bplace:anon'], 'no file');
    }

    /** A failure in the mentions step is logged, and the quote is answered as if mentions were not asked for. */
    public function testAFailingMentionsStepLeavesTheQuoteWhole(): void
    {
        $this->mention('TEST1', 1, 1, 0, 5, 'bpers:petrus');
        $plain = $this->quote(['query' => 'Gen1:1-2', 'version' => 'TEST1']);
        $pdo   = $this->getConnection();
        $log   = new TestHandler();
        $pdo->exec('ALTER TABLE bpe_mention RENAME TO bpe_mention_away');
        LoggerFactory::create('api')->pushHandler($log);
        try {
            $body = $this->quote(['query' => 'Gen1:1-2', 'version' => 'TEST1', 'mentions' => '1']);
        } finally {
            LoggerFactory::create('api')->popHandler();
            $this->getConnection()->exec('ALTER TABLE bpe_mention_away RENAME TO bpe_mention');
        }
        self::assertTrue($log->hasWarningThatContains('mentions'), 'the failure is logged as a warning');
        self::assertArrayNotHasKey('entities', $body);
        self::assertSame($plain['results'], $body['results']);
    }

    public function testWithoutTheParameterNothingChanges(): void
    {
        $this->mention('TEST1', 1, 1, 0, 5, 'bpers:petrus');
        $plain = $this->quote(['query' => 'Gen1:1-3', 'version' => 'TEST1']);
        $off   = $this->quote(['query' => 'Gen1:1-3', 'version' => 'TEST1', 'mentions' => '0']);
        self::assertArrayNotHasKey('entities', $plain);
        self::assertArrayNotHasKey('mentions', self::rows($plain)[0]);
        self::assertArrayNotHasKey('entities', $off);
        self::assertSame($plain['results'], $off['results']);

        // Other formats ignore it, without an error.
        foreach (['xml' => 'application/xml', 'html' => 'text/html'] as $format => $accept) {
            $out = $this->raw(['query' => 'Gen1:1', 'version' => 'TEST1', 'mentions' => '1', 'return' => $format], $accept);
            self::assertStringNotContainsString('mentions', $out, $format);
            self::assertStringNotContainsString('petrus', $out, $format);
        }
    }

    public function testCandidatesEntitiesAreIncluded(): void
    {
        $this->mention('TEST1', 1, 1, 3, 8, null, ['bpers:ioannes', 'bpers:marcus']);
        $body = $this->quote(['query' => 'Gen1:1', 'version' => 'TEST1', 'mentions' => '1']);
        self::assertSame([['start' => 3, 'end' => 8, 'candidates' => ['bpers:ioannes', 'bpers:marcus']]], self::rows($body)[0]['mentions']);
        self::assertEqualsCanonicalizing(['bpers:ioannes', 'bpers:marcus'], array_keys(self::entities($body)));
    }
}
