<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Cbppedr;

use BibleGet\Api\Cbppedr\MentionLoader;
use BibleGet\Api\Cbppedr\VerseCountException;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\EntityRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;
use BibleGet\Tests\Support\CountingStatement;

/**
 * Loading CBPPEDR's mentions (migration 073) against an edition's rows: TEST1 gets a few rows of invented Latin in
 * book 60 and in an Esther chapter of both texts (19:90), linked to synthetic CBTUDR units.
 */
final class MentionLoaderTest extends DatabaseTestCase
{
    private const string MIGRATIONS = __DIR__ . '/../../../migrations/';

    private const array TEXTS = [
        [60, 1, 1, null, 'Ecce 𝔄 et <i>Petrus</i> venit.'],
        [60, 1, 2, null, 'Et Marcus ambulat cum Petro in via.'],
        [60, 1, 3, null, 'Paulus scribit epistulam.'],
        [19, 90, 1, 'GREEK', 'Mardochaeus sedet ad portam.'],
        [19, 90, 1, 'HEBREW', 'Esther regina loquitur.'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        foreach (['053-btu-review-store.sql', '072-cbppedr.sql', '073-cbppedr-mentions.sql'] as $file) {
            $pdo->exec((string) file_get_contents(self::MIGRATIONS . $file));
        }
        $this->clean();
        $row = $pdo->prepare('INSERT INTO "TEST1" (book, chapter, verse, text_tradition, text, testament, section) VALUES (?, ?, ?, ?, ?, 1, 1)');
        foreach (self::TEXTS as $t) {
            $row->execute($t);
        }
        $pdo->exec("INSERT INTO btu_link (edition, book, ord, ref, units, kind, status) VALUES
            ('TEST1', 60, 0, '1:1', ARRAY['btu:fx.1.1'], 'same', 'rule'),
            ('TEST1', 60, 1, '1:2', ARRAY['btu:fx.1.2'], 'same', 'reviewed'),
            ('TEST1', 60, 2, '1:3', ARRAY['btu:fx.1.3'], 'same', 'draft'),
            ('TEST1', 19, 0, '90:1@GREEK', ARRAY['btu:est.90.1'], 'same', 'rule'),
            ('TEST1', 19, 1, '90:1@HEBREW', ARRAY['btu:est.90.1'], 'same', 'rule')");
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
        $pdo = $this->getConnection();
        $pdo->exec('TRUNCATE bpe_mention, bpe_detail, bpe_verse_count, bpe_entry, bpe_attested, bpe_label, bpe_release, btu_link RESTART IDENTITY CASCADE');
        $pdo->exec('DELETE FROM "TEST1" WHERE book = 60 OR (book = 19 AND chapter = 90)');
    }

    /**
     * @param list<string> $attested
     * @param list<string> $mentioned
     * @return array{id: string, type: string, kind: string, names: array<string, string>, description: array<string, string>, qid: ?string, qid_status: ?string, uncertain: bool, attested: list<string>, labels: array<string, list<string>>, mentioned: list<string>}
     */
    private static function entry(string $id, array $attested, array $mentioned = []): array
    {
        return [
            'id'          => $id,
            'type'        => str_starts_with($id, 'bplace:') ? 'place' : 'person',
            'kind'        => 'individual',
            'names'       => ['la' => 'N'],
            'description' => ['en' => 'd'],
            'qid'         => null,
            'qid_status'  => null,
            'uncertain'   => false,
            'attested'    => $attested,
            'labels'      => [],
            'mentioned'   => $mentioned,
        ];
    }

    /** @return list<array{id: string, type: string, kind: string, names: array<string, string>, description: array<string, string>, qid: ?string, qid_status: ?string, uncertain: bool, attested: list<string>, labels: array<string, list<string>>, mentioned: list<string>}> */
    private static function entries(): array
    {
        return [
            self::entry('bpers:petrus', ['btu:fx.1.1'], ['btu:fx.1.2', 'btu:fx.1.1']),
            self::entry('bpers:marcus', ['btu:fx.1.2']),
            self::entry('bpers:paulus', []),
            self::entry('bpers:mardochaeus', ['btu:est.90.1']),
            self::entry('bpers:esther', ['btu:est.90.1']),
            self::entry('bplace:via', ['btu:fx.1.2']),
        ];
    }

    private function plain(int $book, int $chapter, int $verse, ?string $tradition = null): string
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT text_plain FROM "TEST1" WHERE book = ? AND chapter = ? AND verse = ? AND text_tradition IS NOT DISTINCT FROM ?'
        );
        $stmt->execute([$book, $chapter, $verse, $tradition]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * A mention of a word of the plain text as CBPPEDR records it: UTF-16 positions and the check of the words.
     *
     * @param string|list<string> $who an entry, or the candidates
     * @return array{start: int, end: int, check: string, entry: ?string, candidates: ?list<string>}
     */
    private static function mention(string $plain, string $word, string|array $who): array
    {
        $at = mb_strpos($plain, $word);
        self::assertIsInt($at);
        $start = intdiv(strlen(mb_convert_encoding(mb_substr($plain, 0, $at), 'UTF-16LE', 'UTF-8')), 2);
        $end   = $start + intdiv(strlen(mb_convert_encoding($word, 'UTF-16LE', 'UTF-8')), 2);
        return [
            'start'      => $start,
            'end'        => $end,
            'check'      => substr(hash('sha256', $word), 0, 8),
            'entry'      => is_string($who) ? $who : null,
            'candidates' => is_array($who) ? $who : null,
        ];
    }

    /**
     * @param list<array{start: int, end: int, check: string, entry: ?string, candidates: ?list<string>}> $mentions
     * @return array{hash: string, mentions: list<array{start: int, end: int, check: string, entry: ?string, candidates: ?list<string>}>}
     */
    private static function unit(string $plain, array $mentions): array
    {
        return ['hash' => hash('sha256', $plain), 'mentions' => $mentions];
    }

    /** @return array<string, array{hash: string, mentions: list<array{start: int, end: int, check: string, entry: ?string, candidates: ?list<string>}>}> */
    private function units(): array
    {
        $one = $this->plain(60, 1, 1);
        $two = $this->plain(60, 1, 2);
        $gr  = $this->plain(19, 90, 1, 'GREEK');
        $he  = $this->plain(19, 90, 1, 'HEBREW');
        return [
            'btu:fx.1.1'          => self::unit($one, [self::mention($one, 'Petrus', 'bpers:petrus')]),
            'btu:fx.1.2'          => self::unit($two, [
                self::mention($two, 'Marcus', 'bpers:marcus'),
                self::mention($two, 'Petro', 'bpers:petrus'),
                self::mention($two, 'via', ['bplace:via']),
            ]),
            'btu:est.90.1@GREEK'  => self::unit($gr, [self::mention($gr, 'Mardochaeus', 'bpers:mardochaeus')]),
            'btu:est.90.1@HEBREW' => self::unit($he, [self::mention($he, 'Esther', 'bpers:esther')]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->getConnection()->query($sql);
        self::assertNotFalse($stmt);
        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->getConnection()->query("SELECT count(*) FROM $table")?->fetchColumn();
    }

    private function loadEntries(): EntityRepository
    {
        $repo = new EntityRepository($this->getConnection());
        $repo->replaceAll(self::entries(), 'c1');
        return $repo;
    }

    public function testSkipsAUnitWhoseHashDiffers(): void
    {
        $this->loadEntries();
        $units                       = $this->units();
        $units['btu:fx.1.1']['hash'] = hash('sha256', 'Ecce aliud.');
        $result                      = MentionLoader::load($this->getConnection(), 'TEST1', ['btu:fx.1.1' => $units['btu:fx.1.1']]);
        self::assertSame(['btu:fx.1.1'], $result['stale']);
        self::assertSame(0, $result['loaded']);
        self::assertSame(0, $this->rowsIn('bpe_mention'));
    }

    /**
     * The database's text_plain (whose \s may take in non-ASCII spaces, by locale) must be the plain text PlainTextMap
     * derives, or every position after the difference would be shifted: such a unit is stale too.
     */
    public function testSkipsAUnitWhoseTextPlainIsNotTheMapsPlainText(): void
    {
        $this->loadEntries();
        $pdo = $this->getConnection();
        $pdo->exec('ALTER TABLE "TEST1" DISABLE TRIGGER USER');
        try {
            $pdo->prepare('UPDATE "TEST1" SET text = ?, text_plain = ? WHERE book = 60 AND chapter = 1 AND verse = 1')
                ->execute(["Ecce\u{2003}\u{2003}Petrus venit.", 'Ecce Petrus venit.']);
        } finally {
            $pdo->exec('ALTER TABLE "TEST1" ENABLE TRIGGER USER');
        }
        $plain  = $this->plain(60, 1, 1);
        $result = MentionLoader::load($pdo, 'TEST1', ['btu:fx.1.1' => self::unit($plain, [self::mention($plain, 'Petrus', 'bpers:petrus')])]);
        self::assertSame(['btu:fx.1.1 text_plain'], $result['stale']);
        self::assertSame(0, $result['loaded']);
        self::assertSame(0, $this->rowsIn('bpe_mention'));
    }

    public function testSkipsASpanWhoseCheckDiffers(): void
    {
        $this->loadEntries();
        $unit                         = $this->units()['btu:fx.1.2'];
        $unit['mentions'][1]['check'] = 'deadbeef';
        $unit['mentions'][2]['end']   = 400; // past the text: no check can match
        $result                       = MentionLoader::load($this->getConnection(), 'TEST1', ['btu:fx.1.2' => $unit]);
        self::assertSame(1, $result['loaded']);
        self::assertCount(2, $result['bad_check']);
        self::assertStringStartsWith('btu:fx.1.2 ', $result['bad_check'][0]);
        self::assertSame(['bpers:marcus'], array_column($this->rows('SELECT entry FROM bpe_mention'), 'entry'));
    }

    /** A unit linked by a draft only, or not linked at all, has no row. */
    public function testReportsAUnitWithNoRow(): void
    {
        $this->loadEntries();
        $plain  = $this->plain(60, 1, 3);
        $unit   = self::unit($plain, [self::mention($plain, 'Paulus', 'bpers:paulus')]);
        $result = MentionLoader::load($this->getConnection(), 'TEST1', ['btu:fx.1.3' => $unit, 'btu:fx.9.9' => $unit]);
        self::assertSame(['btu:fx.1.3', 'btu:fx.9.9'], $result['no_row']);
        self::assertSame(0, $result['loaded']);
    }

    /** UTF-16 units of text_plain in (an astral letter counts two), code points of the formatted text out. */
    public function testConvertsPositionsToTextCodePoints(): void
    {
        $this->loadEntries();
        $unit   = $this->units()['btu:fx.1.1'];
        $result = MentionLoader::load($this->getConnection(), 'TEST1', ['btu:fx.1.1' => $unit]);
        self::assertSame(1, $result['loaded']);
        self::assertSame(11, $unit['mentions'][0]['start']);
        self::assertSame([
            [
                'edition'    => 'TEST1',
                'book'       => 60,
                'chapter'    => 1,
                'verse'      => 1,
                'segment'    => null,
                'tradition'  => null,
                'unit'       => 'btu:fx.1.1',
                'plain_hash' => $unit['hash'],
                'text_hash'  => hash('sha256', 'Ecce 𝔄 et <i>Petrus</i> venit.'),
                'start_cp'   => 10,
                'end_cp'     => 23,
                'entry'      => 'bpers:petrus',
                'candidates' => null,
            ]
        ], $this->rows('SELECT edition, book, chapter, verse, segment, tradition, unit, plain_hash, text_hash, start_cp, end_cp, entry, candidates FROM bpe_mention'));
        self::assertSame('<i>Petrus</i>', mb_substr('Ecce 𝔄 et <i>Petrus</i> venit.', 10, 13));
    }

    /** Esther's unit is linked to a verse of each text: the key's suffix picks one, a key without one has two. */
    public function testLoadsEstherTraditionsSeparately(): void
    {
        $this->loadEntries();
        $units  = $this->units();
        $result = MentionLoader::load($this->getConnection(), 'TEST1', [
            'btu:est.90.1@GREEK'  => $units['btu:est.90.1@GREEK'],
            'btu:est.90.1@HEBREW' => $units['btu:est.90.1@HEBREW'],
            'btu:est.90.1'        => $units['btu:est.90.1@HEBREW'],
        ]);
        self::assertSame(2, $result['loaded']);
        self::assertSame(['btu:est.90.1'], $result['no_row']);
        self::assertSame([
            ['tradition' => 'GREEK', 'entry' => 'bpers:mardochaeus', 'start_cp' => 0, 'end_cp' => 11],
            ['tradition' => 'HEBREW', 'entry' => 'bpers:esther', 'start_cp' => 0, 'end_cp' => 6],
        ], $this->rows('SELECT tradition, entry, start_cp, end_cp FROM bpe_mention WHERE book = 19 AND chapter = 90 ORDER BY tradition'));
    }

    /** Ruling B: a mention naming an entry the catalog does not have is dropped, and reported. */
    public function testDropsAMentionOfAnUnknownEntry(): void
    {
        $this->loadEntries();
        $plain  = $this->plain(60, 1, 1);
        $result = MentionLoader::load($this->getConnection(), 'TEST1', [
            'btu:fx.1.1' => self::unit($plain, [self::mention($plain, 'Petrus', ['bpers:petrus', 'bpers:nemo'])]),
        ]);
        self::assertSame(0, $result['loaded']);
        self::assertSame(['btu:fx.1.1 bpers:nemo'], $result['unknown_ids']);
    }

    /** A settled mention's unit no source cites is in the entry's index; a unit also attested stays attested. */
    public function testMentionedUnitsAreInTheIndex(): void
    {
        $repo = $this->loadEntries();
        self::assertSame(
            [['unit' => 'btu:fx.1.1', 'ord' => 0, 'mentioned' => false], ['unit' => 'btu:fx.1.2', 'ord' => 1, 'mentioned' => true]],
            $this->rows("SELECT unit, ord, mentioned FROM bpe_attested WHERE entry = 'bpers:petrus' ORDER BY ord")
        );
        $byId = array_column($repo->index('TEST1', 'person')['entries'], null, 'id');
        self::assertSame([[60, 1, 1, null, null], [60, 1, 2, null, null]], $byId['bpers:petrus']['refs']);
    }

    /** After the load commits, each entry's number of verses in an edition with mentions, from its index. */
    public function testCountsVersesPerEntry(): void
    {
        $result = ( new EntityRepository($this->getConnection()) )->replaceAll(self::entries(), 'c1', ['TEST1' => $this->units()]);
        self::assertSame(6, $result['mentions']['TEST1']['loaded']);
        self::assertSame([
            ['entry' => 'bpers:esther', 'verses' => 1],
            ['entry' => 'bpers:marcus', 'verses' => 1],
            ['entry' => 'bpers:mardochaeus', 'verses' => 1],
            ['entry' => 'bpers:petrus', 'verses' => 2],
            ['entry' => 'bplace:via', 'verses' => 1],
        ], $this->rows("SELECT entry, verses FROM bpe_verse_count WHERE edition = 'TEST1' ORDER BY entry"));
        self::assertSame(5, $result['verse_counts']);
    }

    /** Entries, mentions and details are replaced together: a load that fails leaves the previous one whole. */
    public function testReplacesEverythingInOneTransaction(): void
    {
        $details = ['bpers:petrus' => ['image' => null, 'wikipedia' => ['la' => 'Petrus'], 'coords' => null], 'bpers:nemo' => ['image' => null, 'wikipedia' => ['la' => 'Nemo'], 'coords' => null]];
        $first   = ( new EntityRepository($this->getConnection()) )->replaceAll(self::entries(), 'c1', ['TEST1' => $this->units()], $details);
        self::assertSame(1, $first['details']);
        self::assertSame(['bpers:nemo'], $first['unknown_details']);
        self::assertSame('{"la": "Petrus"}', $this->rows('SELECT wikipedia::text AS w FROM bpe_detail')[0]['w']);

        $p   = Connection::params();
        $pdo = new class ("pgsql:host={$p['host']};port={$p['port']};dbname={$p['dbname']}", $p['user'], $p['password']) extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 'INSERT INTO bpe_detail')) {
                    throw new \RuntimeException('failed on purpose');
                }
                return parent::prepare($query, $options);
            }
        };
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        try {
            ( new EntityRepository($pdo) )->replaceAll([self::entry('bpers:marcus', [])], 'c2', [], $details);
            self::fail('The load should have failed');
        } catch (\RuntimeException $e) {
            self::assertSame('failed on purpose', $e->getMessage());
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame(6, $this->rowsIn('bpe_entry'));
        self::assertSame(6, $this->rowsIn('bpe_mention'));
        self::assertSame(1, $this->rowsIn('bpe_detail'));
        self::assertSame(5, $this->rowsIn('bpe_verse_count'));
        self::assertSame(1, $this->rowsIn('bpe_release'));

        // A load that succeeds replaces every one of them, candidate-only mentions too.
        ( new EntityRepository($this->getConnection()) )->replaceAll([self::entry('bpers:marcus', []), self::entry('bplace:via', [])], 'c2');
        self::assertSame(0, $this->rowsIn('bpe_mention'));
        self::assertSame(0, $this->rowsIn('bpe_detail'));
        self::assertSame(0, $this->rowsIn('bpe_verse_count'));
    }

    /** A connection of its own to the test database, which runs $statementClass statements when given one. */
    private static function connection(?string $statementClass = null): \PDO
    {
        $p   = Connection::params();
        $pdo = new \PDO("pgsql:host={$p['host']};port={$p['port']};dbname={$p['dbname']}", $p['user'], $p['password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        if ($statementClass !== null) {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [$statementClass, []]);
        }
        return $pdo;
    }

    /** Through an SSH tunnel every round trip counts: the mentions go in about a thousand per statement. */
    public function testLoadsMoreThanAThousandMentionsInBatches(): void
    {
        $this->loadEntries();
        $pdo = $this->getConnection();
        $pdo->prepare('INSERT INTO "TEST1" (book, chapter, verse, text, testament, section) VALUES (60, 2, 1, ?, 1, 1)')
            ->execute([rtrim(str_repeat('Petrus ', 2500))]);
        $pdo->exec("INSERT INTO btu_link (edition, book, ord, ref, units, kind, status) VALUES ('TEST1', 60, 3, '2:1', ARRAY['btu:fx.2.1'], 'same', 'rule')");
        $plain    = $this->plain(60, 2, 1);
        $check    = substr(hash('sha256', 'Petrus'), 0, 8);
        $mentions = [];
        for ($i = 0; $i < 2500; $i++) {
            $mentions[] = ['start' => $i * 7, 'end' => $i * 7 + 6, 'check' => $check, 'entry' => 'bpers:petrus', 'candidates' => null];
        }
        $mentions[] = ['start' => 0, 'end' => 13, 'check' => substr(hash('sha256', 'Petrus Petrus'), 0, 8), 'entry' => null, 'candidates' => ['bpers:petrus', 'bpers:marcus']];

        CountingStatement::$executed = [];
        $result                      = MentionLoader::load(self::connection(CountingStatement::class), 'TEST1', ['btu:fx.2.1' => self::unit($plain, $mentions)]);

        self::assertSame(2501, $result['loaded']);
        self::assertSame([], $result['bad_check']);
        self::assertSame(3, CountingStatement::count('INSERT INTO bpe_mention'), '2501 mentions, 1000 a statement');
        self::assertSame(2501, $this->rowsIn('bpe_mention'));
        self::assertSame(
            [['start_cp' => 0, 'end_cp' => 6], ['start_cp' => 17493, 'end_cp' => 17499]],
            $this->rows('SELECT start_cp, end_cp FROM bpe_mention WHERE entry IS NOT NULL AND start_cp IN (0, 17493) ORDER BY start_cp')
        );
        self::assertSame(
            [['candidates' => '{bpers:petrus,bpers:marcus}', 'start_cp' => 0, 'end_cp' => 13]],
            $this->rows('SELECT candidates::text AS candidates, start_cp, end_cp FROM bpe_mention WHERE entry IS NULL')
        );
    }

    /** When the catalog commits but the verse counts fail, the load says so: it must be run again. */
    public function testReportsAVerseCountThatFailedAfterTheCatalogCommitted(): void
    {
        $p   = Connection::params();
        $pdo = new class ("pgsql:host={$p['host']};port={$p['port']};dbname={$p['dbname']}", $p['user'], $p['password']) extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 'INSERT INTO bpe_verse_count')) {
                    throw new \RuntimeException('failed on purpose');
                }
                return parent::prepare($query, $options);
            }
        };
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        try {
            ( new EntityRepository($pdo) )->replaceAll(self::entries(), 'c1', ['TEST1' => $this->units()]);
            self::fail('The verse counts should have failed');
        } catch (VerseCountException $e) {
            self::assertSame('failed on purpose', $e->getPrevious()?->getMessage());
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame(6, $this->rowsIn('bpe_mention'), 'the catalog committed');
        self::assertSame(0, $this->rowsIn('bpe_verse_count'));
    }
}
