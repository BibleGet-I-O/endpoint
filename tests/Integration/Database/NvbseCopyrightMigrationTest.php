<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 052: the Nova Vulgata is copyrighted by the Libreria Editrice Vaticana, and its text is the 1986
 * second typical edition.
 */
final class NvbseCopyrightMigrationTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/052-nvbse-copyright.sql';

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->getConnection()->exec("DELETE FROM versions_available WHERE sigla = 'NVBSE'");
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function row(string $sigla): array
    {
        $stmt = $this->getConnection()->prepare('SELECT year, copyright, copyright_holder FROM versions_available WHERE sigla = ?');
        $stmt->execute([$sigla]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        /** @var array<string, mixed> $row */
        return $row;
    }

    public function testFlagsNvbseCopyrightedByLevDatedTheSecondTypicalEditionAndIsIdempotent(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec(
            "INSERT INTO versions_available (sigla, fullname, year, language, type, copyright, copyright_holder) VALUES
                ('NVBSE', 'Nova Vulgata', 1979, 'Latin', 'BIBLE', 0, '')"
        );
        $sql = (string) file_get_contents(self::MIGRATION);

        $pdo->exec($sql);
        $pdo->exec($sql);

        self::assertEquals(['year' => 1986, 'copyright' => 1, 'copyright_holder' => 'Libreria Editrice Vaticana'], $this->row('NVBSE'));
        // The public-domain Latin edition beside it is untouched.
        self::assertEquals(['year' => 1592, 'copyright' => 0, 'copyright_holder' => ''], $this->row('VGCL'));
    }
}
