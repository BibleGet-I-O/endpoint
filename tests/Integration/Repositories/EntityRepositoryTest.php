<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Repositories;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\EntityRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;

/** The CBPPEDR catalogs as loaded (migration 072). Ids and units are synthetic. */
class EntityRepositoryTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/072-cbppedr.sql';
    private const string BTU       = __DIR__ . '/../../../migrations/053-btu-review-store.sql';
    private const string MENTIONS  = __DIR__ . '/../../../migrations/073-cbppedr-mentions.sql';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        $pdo->exec((string) file_get_contents(self::BTU));
        $pdo->exec((string) file_get_contents(self::MIGRATION));
        $pdo->exec((string) file_get_contents(self::MIGRATION)); // idempotent
        $pdo->exec((string) file_get_contents(self::MENTIONS));
        $pdo->exec('TRUNCATE bpe_entry, bpe_attested, bpe_label, bpe_release, btu_link, btu_unit RESTART IDENTITY CASCADE');
        $pdo->exec("INSERT INTO btu_unit (id, book, ord) VALUES ('btu:gn.1.1', 1, 0), ('btu:gn.1.2', 1, 1)");
    }

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->getConnection()->exec('TRUNCATE bpe_entry, bpe_attested, bpe_label, bpe_release, btu_link, btu_unit RESTART IDENTITY CASCADE');
        }
        parent::tearDown();
    }

    /**
     * @param list<string> $attested
     * @param array<string, list<string>> $labels
     * @return array{id: string, type: string, kind: string, names: array<string, string>, description: array<string, string>, qid: ?string, qid_status: ?string, uncertain: bool, attested: list<string>, labels: array<string, list<string>>}
     */
    private static function entry(string $id, array $attested, array $labels = []): array
    {
        return [
            'id'          => $id,
            'type'        => 'person',
            'kind'        => 'individual',
            'names'       => ['la' => 'N', 'it' => 'N', 'en' => 'N'],
            'description' => ['en' => 'd'],
            'qid'         => null,
            'qid_status'  => null,
            'uncertain'   => false,
            'attested'    => $attested,
            'labels'      => $labels
        ];
    }

    public function testReplacesEverythingInOneLoad(): void
    {
        $repo  = new EntityRepository($this->getConnection());
        $first = $repo->replaceAll([self::entry('bpers:a', ['btu:gn.1.1'], ['btu:gn.1.1' => ['NVBSE']]), self::entry('bpers:b', ['btu:gn.1.2'])], 'c1');
        self::assertSame(
            ['entries' => 2, 'attested' => 2, 'mentioned' => 0, 'labels' => 1, 'unknown_units' => [], 'mentions' => [], 'details' => 0, 'unknown_details' => [], 'verse_counts' => 0],
            $first
        );
        $repo->replaceAll([self::entry('bpers:b', ['btu:gn.1.2'])], 'c2');
        $pdo = $this->getConnection();
        self::assertSame(1, (int) $pdo->query('SELECT count(*) FROM bpe_entry')?->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT count(*) FROM bpe_label')?->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT count(*) FROM bpe_release')?->fetchColumn());
        self::assertSame('c2', $pdo->query('SELECT commit FROM bpe_release ORDER BY id DESC LIMIT 1')?->fetchColumn());
    }

    public function testReportsUnitsCbtudrDoesNotKnow(): void
    {
        $result = ( new EntityRepository($this->getConnection()) )->replaceAll([self::entry('bpers:a', ['btu:gn.1.1', 'btu:xx.9.9'])], 'c1');
        self::assertSame(['btu:xx.9.9'], $result['unknown_units']);
        self::assertSame(2, (int) $this->getConnection()->query('SELECT count(*) FROM bpe_attested')?->fetchColumn());
    }

    /** The reads of an index see one release: a load committed between them cannot mix two. */
    public function testReadsTheIndexInOneReadOnlySnapshot(): void
    {
        ( new EntityRepository($this->getConnection()) )->replaceAll([self::entry('bpers:a', ['btu:gn.1.1'])], 'c1');
        $this->getConnection()->exec("INSERT INTO btu_link (edition, book, ord, ref, units, kind, status) VALUES ('NOSUCH', 1, 0, '1:1', '{btu:gn.1.1}', 'same', 'rule')");
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
        $index = ( new EntityRepository($pdo) )->index('NOSUCH', 'person');
        self::assertSame(['bpers:a'], array_column($index['entries'], 'id'));
        self::assertNotSame([], $pdo->seen);
        self::assertSame(['repeatable read on'], array_values(array_unique($pdo->seen)));
        self::assertFalse($pdo->inTransaction());
    }

    /**
     * A load holds the CBPPEDR advisory lock from the catalog's replacement through the verse counts: a load waits
     * for another one to finish, and once done holds no advisory lock.
     */
    public function testSerializesLoadsWithAnAdvisoryLock(): void
    {
        $p     = Connection::params();
        $other = new \PDO("pgsql:host={$p['host']};port={$p['port']};dbname={$p['dbname']}", $p['user'], $p['password']);
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->query("SELECT pg_advisory_lock(hashtext('bibleget.cbppedr.load'))");
        $pdo = $this->getConnection();
        $pdo->exec("SET lock_timeout = '200ms'");
        try {
            ( new EntityRepository($pdo) )->replaceAll([self::entry('bpers:a', ['btu:gn.1.1'])], 'c1');
            self::fail('A load ran while another one held the lock');
        } catch (\PDOException $e) {
            self::assertSame('55P03', $e->getCode()); // lock_not_available
        } finally {
            $other->query("SELECT pg_advisory_unlock(hashtext('bibleget.cbppedr.load'))");
        }
        try {
            ( new EntityRepository($pdo) )->replaceAll([self::entry('bpers:a', ['btu:gn.1.1'])], 'c1');
        } finally {
            $pdo->exec('RESET lock_timeout');
        }
        $held = $pdo->query("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()");
        self::assertSame(0, (int) $held?->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT count(*) FROM bpe_entry')?->fetchColumn());
    }

    /** A caller's own transaction must give one snapshot too: read committed would not. */
    public function testRefusesACallersReadCommittedTransaction(): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            ( new EntityRepository($pdo) )->index('NOSUCH', 'person');
        } finally {
            $pdo->rollBack();
        }
    }

    public function testReadsInACallersRepeatableReadTransaction(): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        try {
            self::assertSame([], ( new EntityRepository($pdo) )->index('NOSUCH', 'person')['entries']);
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }
}
