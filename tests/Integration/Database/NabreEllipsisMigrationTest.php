<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 067: NABRE's garbled ellipses (U+FFFD) become "…". Synthetic text, in a transaction rolled back.
 */
final class NabreEllipsisMigrationTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/067-nabre-ellipsis.sql';

    public function testTheReplacementCharacterBecomesAnEllipsis(): void
    {
        $pdo = $this->getConnection();
        $sql = (string) file_get_contents(self::MIGRATION);
        $pdo->exec($sql); // without the table: nothing to do
        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP TABLE IF EXISTS "NABRE"');
            $pdo->exec('CREATE TABLE "NABRE" (sort_order SERIAL, text TEXT NOT NULL, text_plain TEXT NOT NULL DEFAULT \'\')');
            $pdo->exec('INSERT INTO "NABRE" (text) VALUES (\'how long\' || chr(65533) || \'?\'), (\'untouched\')');
            $pdo->exec($sql);
            $pdo->exec($sql); // idempotent
            $rows = $pdo->query('SELECT text FROM "NABRE" ORDER BY sort_order');
            self::assertNotFalse($rows);
            self::assertSame(["how long\u{2026}?", 'untouched'], $rows->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            $pdo->rollBack();
        }
    }
}
