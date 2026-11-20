<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 054 checks the private NVBSE-vatican archive's data half (the Nova Vulgata's text can't be a fixture)
 * and recounts NVBSE_idx. Here: it does nothing without an NVBSE table, and refuses one that doesn't hold the
 * archive's text, before touching the index.
 */
final class NvbseBracketedVersesMigrationTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/054-nvbse-bracketed-verses.sql';

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
                    verse_segment VARCHAR(4), text_tradition VARCHAR(10), text VARCHAR(2000))');
        $pdo->exec('CREATE TABLE "NVBSE_idx" (book INT PRIMARY KEY, chapters INT, verses_count TEXT, verses_last TEXT)');
        // Act 24 as before the data half: 24:8 still held in 24:6.
        $pdo->exec("INSERT INTO \"NVBSE\" VALUES (1, 51, 24, 6, NULL, NULL, 'qui etiam ... (7) 8 a quo poteris ...'),
                                                (2, 51, 24, 9, NULL, NULL, 'Adiecerunt autem ...')");
        $pdo->exec("INSERT INTO \"NVBSE_idx\" VALUES (51, 1, '7', '9')");

        try {
            $pdo->exec((string) file_get_contents(self::MIGRATION));
            self::fail('054 accepted an NVBSE table without the data half');
        } catch (\PDOException $e) {
            self::assertStringContainsString('NVBSE-vatican/migrations/054-nvbse-bracketed-verses.sql', $e->getMessage());
        }

        $idx = $pdo->query('SELECT chapters, verses_count, verses_last FROM "NVBSE_idx"');
        self::assertNotFalse($idx);
        self::assertEquals(['chapters' => 1, 'verses_count' => '7', 'verses_last' => '9'], $idx->fetch(\PDO::FETCH_ASSOC));
    }
}
