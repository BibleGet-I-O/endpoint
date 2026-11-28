<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Services;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\MentionRepository;
use BibleGet\Api\Services\QuoteMentions;
use BibleGet\Tests\Integration\DatabaseTestCase;

/** A quote's mentions and the cards they name are read from one snapshot (migrations 072/073). Ids are synthetic. */
final class QuoteMentionsSnapshotTest extends DatabaseTestCase
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
        $pdo->exec(
            "INSERT INTO bpe_entry (id, type, kind, names, description, qid, qid_status, uncertain)
             VALUES ('bpers:petrus', 'person', 'individual', '{\"la\": \"Petrus\"}', '{}', NULL, NULL, false)"
        );
        $pdo->prepare(
            "INSERT INTO bpe_mention (edition, book, chapter, verse, unit, plain_hash, text_hash, start_cp, end_cp, entry)
             VALUES ('TEST1', 60, 1, 1, 'btu:fx.1.1', ?, ?, 5, 11, 'bpers:petrus')"
        )->execute([hash('sha256', 'Ecce Petrus'), hash('sha256', 'Ecce Petrus')]);
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
        $this->getConnection()->exec('TRUNCATE bpe_mention, bpe_detail, bpe_verse_count, bpe_entry RESTART IDENTITY CASCADE');
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return [['version' => 'TEST1', 'univbooknum' => 60, 'chapter' => 1, 'verse' => 1, 'verse_segment' => null, 'text_tradition' => null, 'text' => 'Ecce Petrus', 'text_plain' => 'Ecce Petrus']];
    }

    public function testReadsMentionsAndCardsInOneReadOnlySnapshot(): void
    {
        $p   = Connection::params();
        $pdo = new class ("pgsql:host={$p['host']};port={$p['port']};dbname={$p['dbname']}", $p['user'], $p['password']) extends \PDO {
            /** @var list<string> */
            public array $seen = [];

            private function note(): void
            {
                $this->seen[] = $this->inTransaction()
                    ? (string) parent::query("SELECT current_setting('transaction_isolation') || ' ' || current_setting('transaction_read_only')")?->fetchColumn()
                    : 'autocommit';
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->note();
                return parent::prepare($query, $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $this->note();
                return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }
        };
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        [$rows, $cards] = QuoteMentions::attach(self::rows(), new MentionRepository($pdo));

        self::assertSame([['start' => 5, 'end' => 11, 'entry' => 'bpers:petrus']], $rows[0]['mentions']);
        self::assertSame(['bpers:petrus'], array_keys($cards));
        self::assertCount(3, $pdo->seen); // editions, mentions, cards
        self::assertSame(['repeatable read on'], array_values(array_unique($pdo->seen)));
        self::assertFalse($pdo->inTransaction());
    }

    /** Inside a caller's repeatable-read transaction the reads simply run in it, which stays open. */
    public function testReadsInACallersRepeatableReadTransaction(): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            [, $cards] = QuoteMentions::attach(self::rows(), new MentionRepository($pdo));
            self::assertSame(['bpers:petrus'], array_keys($cards));
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }

    /** A caller's read-committed transaction would let a load commit between the mention and the card reads. */
    public function testRefusesACallersReadCommittedTransaction(): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            QuoteMentions::attach(self::rows(), new MentionRepository($pdo));
        } finally {
            $pdo->rollBack();
        }
    }
}
