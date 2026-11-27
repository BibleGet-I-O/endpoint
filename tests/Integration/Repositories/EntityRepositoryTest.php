<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Repositories;

use BibleGet\Api\Repositories\EntityRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;

/** The CBPPEDR catalogs as loaded (migration 072). Ids and units are synthetic. */
class EntityRepositoryTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/072-cbppedr.sql';
    private const string BTU       = __DIR__ . '/../../../migrations/053-btu-review-store.sql';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        $pdo->exec((string) file_get_contents(self::BTU));
        $pdo->exec((string) file_get_contents(self::MIGRATION));
        $pdo->exec((string) file_get_contents(self::MIGRATION)); // idempotent
        $pdo->exec('TRUNCATE bpe_entry, bpe_attested, bpe_label, bpe_release, btu_unit RESTART IDENTITY CASCADE');
        $pdo->exec("INSERT INTO btu_unit (id, book, ord) VALUES ('btu:gn.1.1', 1, 0), ('btu:gn.1.2', 1, 1)");
    }

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->getConnection()->exec('TRUNCATE bpe_entry, bpe_attested, bpe_label, bpe_release, btu_unit RESTART IDENTITY CASCADE');
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
        self::assertSame(['entries' => 2, 'attested' => 2, 'labels' => 1, 'unknown_units' => []], $first);
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
}
