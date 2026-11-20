<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 055 checks the private NVBSE-vatican archive's data half (the Nova Vulgata's text can't be a fixture)
 * and recounts NVBSE_idx. Here: it does nothing without an NVBSE table, and refuses one that doesn't hold the
 * archive's text, before touching the index.
 */
final class NvbseOmittedVersesMigrationTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/055-nvbse-omitted-verses.sql';

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->getConnection()->exec('DROP TABLE IF EXISTS "NVBSE", "NVBSE_idx"');
        }
        parent::tearDown();
    }

    public function testWithoutAnNvbseTableItDoesNothing(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec('DROP TABLE IF EXISTS "NVBSE", "NVBSE_idx"');

        $pdo->exec((string) file_get_contents(self::MIGRATION));

        $exists = $pdo->query("SELECT to_regclass('public.\"NVBSE\"')");
        self::assertNotFalse($exists);
        self::assertNull($exists->fetchColumn());
    }

    public function testATableWithoutTheDataHalfIsRefusedAndItsIndexLeftAlone(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec('CREATE TABLE "NVBSE" (sort_order INT PRIMARY KEY, book INT, chapter INT, verse INT,
                    verse_segment VARCHAR(4), text_tradition VARCHAR(10), text VARCHAR(2000), omitted BOOLEAN NOT NULL DEFAULT false)');
        $pdo->exec('CREATE TABLE "NVBSE_idx" (book INT PRIMARY KEY, chapters INT, verses_count TEXT, verses_last TEXT)');
        // Act 24 as before the data half: 24:7 still a marker at the end of 24:6, with no row.
        $pdo->exec("INSERT INTO \"NVBSE\" VALUES (1, 51, 24, 6, NULL, NULL, 'qui etiam ... apprehendimus, (7)'),
                                                (2, 51, 24, 8, NULL, NULL, 'a quo poteris ...')");
        $pdo->exec("INSERT INTO \"NVBSE_idx\" VALUES (51, 1, '7', '8')");

        try {
            $pdo->exec((string) file_get_contents(self::MIGRATION));
            self::fail('055 accepted an NVBSE table without the data half');
        } catch (\PDOException $e) {
            self::assertStringContainsString('NVBSE-vatican/migrations/055-nvbse-omitted-verses.sql', $e->getMessage());
        }

        $idx = $pdo->query('SELECT chapters, verses_count, verses_last FROM "NVBSE_idx"');
        self::assertNotFalse($idx);
        self::assertEquals(['chapters' => 1, 'verses_count' => '7', 'verses_last' => '8'], $idx->fetch(\PDO::FETCH_ASSOC));
    }
}
