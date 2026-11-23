<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 065: Sirach's prologue as chapter 0, verse 1. `bibleget_insert_prologue()` puts it before the book's first
 * row (sort orders shift, nothing else moves) and marks the index; `bibleget_split_prologue()` takes a prologue the
 * source glued onto 1:1 (VGCL) off into its own row. Synthetic text only.
 */
final class SirachPrologueMigrationTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/065-sirach-prologue.sql';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        $pdo->exec('DROP TABLE IF EXISTS "PROLTEST", "PROLTEST_idx"');
        $pdo->exec('CREATE TABLE "PROLTEST" (sort_order SERIAL PRIMARY KEY, book INT NOT NULL, chapter INT NOT NULL, verse INT NOT NULL,
            text TEXT NOT NULL, title1 VARCHAR(255), testament SMALLINT NOT NULL DEFAULT 1, section INT NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE "PROLTEST_idx" (book INT PRIMARY KEY, chapters INT, verses_count TEXT, verses_last TEXT, fullname TEXT, abbrev TEXT)');
        $pdo->exec("INSERT INTO \"PROLTEST\" (book, chapter, verse, text, testament, section) VALUES
            (27, 19, 22, 'Wisdom last.', 1, 3), (28, 1, 1, 'Sirach one.', 1, 3), (28, 1, 2, 'Sirach two.', 1, 3), (29, 1, 1, 'Isaiah one.', 1, 4)");
        $pdo->exec('INSERT INTO "PROLTEST_idx" (book, chapters) VALUES (27, 19), (28, 51), (29, 66)');
        $pdo->exec((string) file_get_contents(self::MIGRATION));
        $pdo->exec('ALTER TABLE "PROLTEST_idx" ADD COLUMN IF NOT EXISTS prologue_verses INT NOT NULL DEFAULT 0');
    }

    protected function tearDown(): void
    {
        $this->getConnection()->exec('DROP TABLE IF EXISTS "PROLTEST", "PROLTEST_idx"');
        parent::tearDown();
    }

    /** @return list<string> "chapter:verse text" in reading order */
    private function sirach(): array
    {
        $rows = $this->getConnection()->query('SELECT chapter, verse, text, sort_order FROM "PROLTEST" ORDER BY sort_order');
        self::assertNotFalse($rows);
        $out    = [];
        $orders = [];
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $orders[] = (int) $r['sort_order'];
            $out[]    = $r['chapter'] . ':' . $r['verse'] . ' ' . $r['text'];
        }
        self::assertSame(range(min($orders), min($orders) + count($orders) - 1), $orders, 'sort orders stay contiguous');
        return $out;
    }

    private function prologueVerses(): int
    {
        return (int) $this->getConnection()->query('SELECT prologue_verses FROM "PROLTEST_idx" WHERE book = 28')?->fetchColumn();
    }

    public function testThePrologueGoesBeforeChapterOne(): void
    {
        $this->getConnection()->exec("SELECT bibleget_insert_prologue('PROLTEST', 28, 'A foreword.', 'Foreword')");
        self::assertSame(['19:22 Wisdom last.', '0:1 A foreword.', '1:1 Sirach one.', '1:2 Sirach two.', '1:1 Isaiah one.'], $this->sirach());
        self::assertSame(1, $this->prologueVerses());
        $row = $this->getConnection()->query('SELECT title1, testament, section FROM "PROLTEST" WHERE book = 28 AND chapter = 0')?->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['title1' => 'Foreword', 'testament' => 1, 'section' => 3], $row);
    }

    public function testInsertingAgainReplacesTheText(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec("SELECT bibleget_insert_prologue('PROLTEST', 28, 'A foreword.', 'Foreword')");
        $pdo->exec("SELECT bibleget_insert_prologue('PROLTEST', 28, 'A better foreword.', 'Foreword')");
        self::assertSame(['19:22 Wisdom last.', '0:1 A better foreword.', '1:1 Sirach one.', '1:2 Sirach two.', '1:1 Isaiah one.'], $this->sirach());
    }

    public function testAPrologueGluedOntoTheFirstVerseIsSplitOff(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec("UPDATE \"PROLTEST\" SET text = E'Many great things.\\n<pof>All wisdom</pof>\\n<po>is from the Lord.</po>', title1 = 'Prologus'
            WHERE book = 28 AND chapter = 1 AND verse = 1");
        $pdo->exec("SELECT bibleget_split_prologue('PROLTEST', 28, 'Prologus')");
        self::assertSame(
            ['19:22 Wisdom last.', '0:1 Many great things.', "1:1 <pof>All wisdom</pof>\n<po>is from the Lord.</po>", '1:2 Sirach two.', '1:1 Isaiah one.'],
            $this->sirach()
        );
        self::assertNull($pdo->query('SELECT title1 FROM "PROLTEST" WHERE book = 28 AND chapter = 1 AND verse = 1')?->fetchColumn() ?: null);
        $pdo->exec("SELECT bibleget_split_prologue('PROLTEST', 28, 'Prologus')"); // nothing left to split
        self::assertCount(5, $this->sirach());
    }
}
