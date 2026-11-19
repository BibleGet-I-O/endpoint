<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Repositories;

use BibleGet\Api\Repositories\VersionRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;

class VersionRepositoryTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->getConnection()->exec("UPDATE versions_available SET reader_full_text = FALSE WHERE type = 'BIBLE'");
        }
        parent::tearDown();
    }

    public function testListReturnsEveryBibleVersionWithFlags(): void
    {
        $pdo      = $this->getConnection();
        $siglas   = $pdo->query("SELECT sigla FROM versions_available WHERE type = 'BIBLE' ORDER BY sigla")?->fetchAll(\PDO::FETCH_COLUMN);
        $versions = ( new VersionRepository($pdo) )->list();

        self::assertSame($siglas, array_column($versions, 'sigla'));
        self::assertNotEmpty($versions);
        foreach ($versions as $v) {
            self::assertFalse($v['reader_full_text'], $v['sigla']);
            self::assertIsInt($v['year']);
            self::assertIsString($v['fullname']);
            self::assertIsString($v['language']);
        }
        $byId = array_column($versions, null, 'sigla');
        self::assertTrue($byId['TEST2']['copyright']);
        self::assertFalse($byId['TEST1']['copyright']);
        self::assertSame(2021, $byId['TEST2']['year']);
    }

    public function testSetReaderFullTextFlipsOneEditionOnly(): void
    {
        $repo = new VersionRepository($this->getConnection());
        self::assertTrue($repo->setReaderFullText('TEST2', true));
        $test2 = $repo->find('TEST2');
        self::assertNotNull($test2);
        self::assertTrue($test2['reader_full_text']);
        $drb = $repo->find('DRB');
        self::assertNotNull($drb);
        self::assertFalse($drb['reader_full_text']);

        self::assertTrue($repo->setReaderFullText('TEST2', false));
        $test2 = $repo->find('TEST2');
        self::assertNotNull($test2);
        self::assertFalse($test2['reader_full_text']);
    }

    public function testSetReaderFullTextOnUnknownSiglaReturnsFalse(): void
    {
        $repo = new VersionRepository($this->getConnection());
        self::assertFalse($repo->setReaderFullText('XYZ', true));
        self::assertNull($repo->find('XYZ'));
    }
}
