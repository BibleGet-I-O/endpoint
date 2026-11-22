<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 062: the titles and rights holders of the editions under agreement, as the agreements state them.
 */
final class EditionTitlesMigrationTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/062-edition-titles-and-holders.sql';

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->getConnection()->exec("DELETE FROM versions_available WHERE sigla IN ('NABRE', 'BLPD', 'CEI2008')");
        }
        parent::tearDown();
    }

    /** @return array<string, array{fullname: string, copyright_holder: string}> */
    private function editions(): array
    {
        $stmt = $this->getConnection()->query(
            "SELECT sigla, fullname, copyright_holder FROM versions_available WHERE sigla IN ('NABRE', 'BLPD', 'CEI2008')"
        );
        self::assertNotFalse($stmt);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['sigla']] = ['fullname' => (string) $row['fullname'], 'copyright_holder' => (string) $row['copyright_holder']];
        }
        ksort($out);
        return $out;
    }

    public function testSetsTheTitlesAndHoldersTheAgreementsNameAndIsIdempotent(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec(
            "INSERT INTO versions_available (sigla, fullname, year, language, copyright_holder, copyright, type) VALUES
                ('NABRE', 'New American Bible - Revised Edition', 2011, 'en', 'United States Conference of Catholic Bishops', 1, 'BIBLE'),
                ('BLPD', 'Libro del Pueblo de Dios', 2015, 'es', 'Fundación Palabra de Vida y Editorial Verbo Divino', 1, 'BIBLE'),
                ('CEI2008', 'Conferenza Episcopale Italiana', 2008, 'it', 'Fondazione di Religione “Santi Francesco d’Assisi e Caterina da Siena”', 1, 'BIBLE')"
        );
        $sql = (string) file_get_contents(self::MIGRATION);

        $pdo->exec($sql);
        $after = $this->editions();
        $pdo->exec($sql);

        self::assertSame([
            'BLPD'    => ['fullname' => 'La Biblia. Libro del Pueblo de Dios', 'copyright_holder' => 'Fundación Palabra de Vida y Editorial Verbo Divino'],
            'CEI2008' => ['fullname' => 'La Sacra Bibbia (CEI 2008)', 'copyright_holder' => 'Fondazione di Religione “Santi Francesco d’Assisi e Caterina da Siena”'],
            'NABRE'   => ['fullname' => 'New American Bible, Revised Edition', 'copyright_holder' => 'Confraternity of Christian Doctrine, Inc.'],
        ], $after);
        self::assertSame($after, $this->editions());
    }

    public function testAnAbsentEditionIsNoError(): void
    {
        $this->getConnection()->exec((string) file_get_contents(self::MIGRATION));
        self::assertSame([], $this->editions());
    }
}
