<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/** Migration 070: Obadiah's three headings in NABRE. Synthetic rows, in a transaction rolled back. */
final class ObadiahHeadingsMigrationTest extends DatabaseTestCase
{
    public function testTheThreeHeadingsAreSetWhereEmpty(): void
    {
        $pdo = $this->getConnection();
        $sql = (string) file_get_contents(__DIR__ . '/../../../migrations/070-nabre-obadiah-headings.sql');
        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP TABLE IF EXISTS "NABRE"');
            $pdo->exec('CREATE TABLE "NABRE" (book INT, chapter INT, verse INT, title1 VARCHAR(255))');
            $pdo->exec("INSERT INTO \"NABRE\" VALUES (38,1,1,NULL),(38,1,2,NULL),(38,1,8,''),(38,1,15,'Kept'),(39,1,1,NULL)");
            $pdo->exec($sql);
            $pdo->exec($sql);
            $rows = $pdo->query('SELECT title1 FROM "NABRE" ORDER BY book, verse');
            self::assertNotFalse($rows);
            self::assertSame(["Edom\u{2019}s Fall Decreed", null, "Edom\u{2019}s Betrayal of Judah", 'Kept', null], $rows->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            $pdo->rollBack();
        }
    }
}
