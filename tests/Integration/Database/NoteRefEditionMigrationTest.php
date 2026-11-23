<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 066: a note's citation may point into another edition (CEI 2008's notes cite the Nova Vulgata's
 * numbering); NULL means the note's own edition.
 */
final class NoteRefEditionMigrationTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/066-note-ref-edition.sql';

    protected function tearDown(): void
    {
        $this->getConnection()->exec("DELETE FROM note WHERE edition = 'TEST1'");
        parent::tearDown();
    }

    public function testACitationCanNameAnotherEditionAndRunsTwice(): void
    {
        $pdo = $this->getConnection();
        $sql = (string) file_get_contents(self::MIGRATION);
        $pdo->exec($sql);
        $pdo->exec($sql);
        $id = (int) $pdo->query("INSERT INTO note (edition, kind, body, source_id) VALUES ('TEST1', 'footnote', 'b', 'x') RETURNING id")->fetchColumn();
        $pdo->exec("INSERT INTO note_ref (note_id, ord, book, chapter, verse, span_start, span_end, edition) VALUES ($id, 1, 28, 24, 40, 0, 2, 'NVBSE')");
        $pdo->exec("INSERT INTO note_ref (note_id, ord, book, chapter, verse, span_start, span_end) VALUES ($id, 2, 28, 24, 1, 3, 5)");
        $rows = $pdo->query("SELECT ord, edition FROM note_ref WHERE note_id = $id ORDER BY ord")->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([[1, 'NVBSE'], [2, null]], $rows);
    }
}
