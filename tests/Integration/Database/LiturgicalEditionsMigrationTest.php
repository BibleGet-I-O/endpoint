<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 051: versions_available.liturgical, set for NABRE, CEI2008 and NVBSE.
 */
final class LiturgicalEditionsMigrationTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/051-liturgical-editions.sql';

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $pdo = $this->getConnection();
            $pdo->exec("DELETE FROM versions_available WHERE sigla IN ('NABRE', 'BLPD')");
            $pdo->exec("UPDATE versions_available SET liturgical = FALSE WHERE type = 'BIBLE'");
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private function flagged(): array
    {
        $stmt = $this->getConnection()->query('SELECT sigla FROM versions_available WHERE liturgical ORDER BY sigla');
        self::assertNotFalse($stmt);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testFlagsTheLiturgicalEditionsOnlyAndIsIdempotent(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec(
            "INSERT INTO versions_available (sigla, fullname, year, language, type) VALUES
                ('NABRE', 'New American Bible', 2011, 'English', 'BIBLE'),
                ('BLPD', 'Libro del Pueblo de Dios', 2015, 'Spanish', 'BIBLE')"
        );
        $sql = (string) file_get_contents(self::MIGRATION);

        // First application: the column is added and the editions flagged.
        $pdo->exec('ALTER TABLE versions_available DROP COLUMN liturgical');
        $pdo->exec($sql);
        $after = $this->flagged();
        $pdo->exec($sql);

        self::assertSame(['NABRE'], $after);
        self::assertSame($after, $this->flagged());
        self::assertNotContains('BLPD', $this->flagged());
    }

    public function testRerunDoesNotReflagAnEditionAnAdminSwitchedOff(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec(
            "INSERT INTO versions_available (sigla, fullname, year, language, type, liturgical) VALUES
                ('NABRE', 'New American Bible', 2011, 'English', 'BIBLE', FALSE)"
        );
        $sql = (string) file_get_contents(self::MIGRATION);

        $pdo->exec($sql);

        self::assertSame([], $this->flagged());
    }
}
