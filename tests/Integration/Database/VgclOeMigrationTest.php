<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 068: VGCL's garbled "œ" (U+009C) is restored. Synthetic text, in a transaction rolled back.
 */
final class VgclOeMigrationTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/068-vgcl-oe.sql';

    public function testTheControlCharacterBecomesOe(): void
    {
        $pdo = $this->getConnection();
        $sql = (string) file_get_contents(self::MIGRATION);
        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP TABLE IF EXISTS "VGCL"');
            $pdo->exec('CREATE TABLE "VGCL" (sort_order SERIAL, text TEXT NOT NULL, text_plain TEXT NOT NULL DEFAULT \'\')');
            $pdo->exec('INSERT INTO "VGCL" (text) VALUES (\'f\' || chr(156) || \'deris\'), (\'untouched\')');
            $pdo->exec($sql);
            $pdo->exec($sql); // idempotent
            $rows = $pdo->query('SELECT text FROM "VGCL" ORDER BY sort_order');
            self::assertNotFalse($rows);
            self::assertSame(["f\u{0153}deris", 'untouched'], $rows->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            $pdo->rollBack();
        }
    }
}
