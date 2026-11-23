<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 064: the editions' notes (footnotes and cross-references), where each marker goes, and the passages a
 * note cites. Empty tables; the copyrighted data comes from private data halves.
 */
final class NotesSchemaTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/064-notes.sql';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        $pdo->exec('DROP TABLE IF EXISTS note_ref, note_anchor, note');
        $sql = (string) file_get_contents(self::MIGRATION);
        $pdo->exec($sql);
        $pdo->exec($sql); // idempotent
    }

    protected function tearDown(): void
    {
        $this->getConnection()->exec('DELETE FROM note');
        parent::tearDown();
    }

    private function insertNote(string $sourceId = '26003006-1', string $kind = 'footnote'): int
    {
        $stmt = $this->getConnection()->prepare(
            "INSERT INTO note (edition, kind, marker, label, body, source_id) VALUES ('NABRE', ?, '*', '3:6', 'Synthetic body.', ?) RETURNING id"
        );
        $stmt->execute([$kind, $sourceId]);
        return (int) $stmt->fetchColumn();
    }

    public function testANoteWithAnchorsAndRefsCascades(): void
    {
        $pdo = $this->getConnection();
        $id  = $this->insertNote();
        $pdo->exec("INSERT INTO note_anchor (note_id, book, chapter, verse, field, position) VALUES ($id, 22, 3, 6, 'text', 42)");
        $pdo->exec("INSERT INTO note_anchor (note_id, book, chapter, verse, field, position) VALUES ($id, 22, 3, 6, 'title1', NULL)");
        $pdo->exec("INSERT INTO note_ref (note_id, ord, book, chapter, verse, span_start, span_end) VALUES ($id, 1, 22, 6, 10, 5, 12)");
        self::assertSame(2, (int) $pdo->query("SELECT count(*) FROM note_anchor WHERE note_id = $id")?->fetchColumn());
        $pdo->exec("DELETE FROM note WHERE id = $id");
        self::assertSame(0, (int) $pdo->query('SELECT count(*) FROM note_anchor')?->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT count(*) FROM note_ref')?->fetchColumn());
    }

    public function testAnUnknownKindIsRejected(): void
    {
        $this->expectException(\PDOException::class);
        $this->insertNote('x-1', 'other');
    }

    public function testAnUnknownFieldIsRejected(): void
    {
        $id = $this->insertNote();
        $this->expectException(\PDOException::class);
        $this->getConnection()->exec("INSERT INTO note_anchor (note_id, book, chapter, verse, field) VALUES ($id, 1, 1, 1, 'body')");
    }

    public function testASourceIdIsUniquePerEdition(): void
    {
        $this->insertNote();
        $this->expectException(\PDOException::class);
        $this->insertNote();
    }
}
