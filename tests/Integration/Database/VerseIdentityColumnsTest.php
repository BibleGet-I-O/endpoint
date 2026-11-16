<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Migration 046 (#184): the verse-identity columns hold one kind of value
 * each (verse_segment, alt_ref, acrostic, text_tradition, sort_order), with
 * a unique key on verse identity, and books carry USFM/OSIS codes.
 *
 * Tests that run the migration do it on an old-shape table "ZZ046" inside a
 * transaction that is rolled back.
 */
final class VerseIdentityColumnsTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/046-verse-identity-columns.sql';

    protected function tearDown(): void
    {
        $pdo = $this->getConnection();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        parent::tearDown();
    }

    /**
     * Create and register the old-shape table, insert $rows, run 046.
     *
     * @param list<array<string, string|int|null>> $rows
     */
    private function migrate(array $rows, int $runs = 1): \PDO
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        $pdo->exec(<<<'SQL'
            CREATE TABLE "ZZ046" (
                "verseID"   SERIAL PRIMARY KEY,
                book        INT NOT NULL,
                chapter     INT NOT NULL,
                verse       INT NOT NULL,
                text        TEXT NOT NULL DEFAULT 'x',
                title_position SMALLINT,
                title1      VARCHAR(255) NOT NULL DEFAULT '',
                title2      VARCHAR(255) NOT NULL DEFAULT '',
                title3      VARCHAR(255) NOT NULL DEFAULT '',
                verseorigin VARCHAR(10) DEFAULT NULL CHECK (verseorigin IN ('GREEK', 'HEBREW')),
                printed_chapter INT,
                verse_label VARCHAR(16),
                omitted     BOOLEAN NOT NULL DEFAULT false,
                verseequiv  VARCHAR(10) DEFAULT NULL,
                versedescr  VARCHAR(10) DEFAULT NULL
            );
            CREATE INDEX "ZZ046_bcv" ON "ZZ046" (book, chapter, verse, verseequiv, verseorigin);
            INSERT INTO versions_available (sigla) VALUES ('ZZ046');
            SQL);
        $insert = $pdo->prepare(
            'INSERT INTO "ZZ046" (book, chapter, verse, verseequiv, versedescr, verseorigin) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $insert->execute([$r['book'], $r['chapter'], $r['verse'], $r['eq'] ?? null, $r['descr'] ?? null, $r['origin'] ?? null]);
        }
        $sql = file_get_contents(self::MIGRATION);
        self::assertIsString($sql, 'migration 046 is missing');
        $sql = (string) preg_replace('/^(BEGIN|COMMIT);\s*$/m', '', $sql);
        for ($i = 0; $i < $runs; $i++) {
            $pdo->exec($sql);
        }
        return $pdo;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(\PDO $pdo, int $verse): array
    {
        $stmt = $pdo->prepare('SELECT verse_segment, alt_ref, acrostic, text_tradition FROM "ZZ046" WHERE verse = ?');
        $stmt->execute([$verse]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    public function testShapesLandInTheirColumns(): void
    {
        $pdo = $this->migrate([
            ['book' => 23, 'chapter' => 10, 'verse' => 1, 'eq' => '4a'],
            ['book' => 23, 'chapter' => 10, 'verse' => 2, 'eq' => '17aa', 'origin' => 'GREEK'],
            ['book' => 23, 'chapter' => 10, 'verse' => 3, 'eq' => '(9,22)'],
            ['book' => 23, 'chapter' => 10, 'verse' => 4, 'eq' => '(113,20>)'],
            ['book' => 23, 'chapter' => 10, 'verse' => 5, 'eq' => '33'],
            ['book' => 23, 'chapter' => 10, 'verse' => 6, 'eq' => '', 'descr' => ''],
            ['book' => 23, 'chapter' => 10, 'verse' => 7, 'descr' => '11:2'],
            ['book' => 23, 'chapter' => 10, 'verse' => 8, 'descr' => '[Alef] '],
        ]);

        self::assertSame(['verse_segment' => 'a', 'alt_ref' => null, 'acrostic' => null, 'text_tradition' => null], self::row($pdo, 1));
        self::assertSame(['verse_segment' => 'aa', 'alt_ref' => null, 'acrostic' => null, 'text_tradition' => 'GREEK'], self::row($pdo, 2));
        self::assertSame('(9,22)', self::row($pdo, 3)['alt_ref']);
        self::assertSame('(113,20>)', self::row($pdo, 4)['alt_ref']);
        self::assertSame('33', self::row($pdo, 5)['alt_ref']);
        self::assertSame(['verse_segment' => null, 'alt_ref' => null, 'acrostic' => null, 'text_tradition' => null], self::row($pdo, 6));
        self::assertSame('11:2', self::row($pdo, 7)['alt_ref']);
        self::assertSame('Alef', self::row($pdo, 8)['acrostic']);
    }

    public function testOldColumnsGoneAndSortOrderIsKey(): void
    {
        $pdo = $this->migrate([['book' => 1, 'chapter' => 1, 'verse' => 1]]);

        $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'ZZ046'");
        self::assertNotFalse($cols);
        $names = $cols->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['verseequiv', 'versedescr', 'verseorigin', 'verseID'] as $old) {
            self::assertNotContains($old, $names);
        }
        foreach (['sort_order', 'verse_segment', 'alt_ref', 'acrostic', 'text_tradition'] as $new) {
            self::assertContains($new, $names);
        }
        $pk = $pdo->query(
            'SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) '
            . "WHERE i.indrelid = '\"ZZ046\"'::regclass AND i.indisprimary"
        );
        self::assertNotFalse($pk);
        self::assertSame(['sort_order'], $pk->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testUnclaimedValueRaises(): void
    {
        try {
            $this->migrate([['book' => 1, 'chapter' => 1, 'verse' => 1, 'eq' => '3b-4']]);
            self::fail('046 accepted a value no rule claims');
        } catch (\PDOException $e) {
            self::assertStringContainsString('ZZ046', $e->getMessage());
            self::assertStringContainsString('verseequiv', $e->getMessage());
            self::assertStringContainsString('3b-4', $e->getMessage());
        }
    }

    public function testSecondRunIsNoOp(): void
    {
        $pdo = $this->migrate([
            ['book' => 1, 'chapter' => 1, 'verse' => 1, 'eq' => '1a'],
            ['book' => 1, 'chapter' => 1, 'verse' => 2, 'eq' => '(2,3)'],
        ], 2);

        self::assertSame('a', self::row($pdo, 1)['verse_segment']);
        self::assertSame('(2,3)', self::row($pdo, 2)['alt_ref']);
    }

    public function testUniqueKeyTreatsNullsAsEqual(): void
    {
        $pdo = $this->migrate([['book' => 1, 'chapter' => 1, 'verse' => 1]]);
        try {
            $pdo->exec('INSERT INTO "ZZ046" (book, chapter, verse) VALUES (1, 1, 1)');
            self::fail('a second unlettered 1:1 was accepted');
        } catch (\PDOException $e) {
            self::assertSame('23505', $e->getCode());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonEmptyColumns(): array
    {
        return ['verse_segment' => ['verse_segment'], 'alt_ref' => ['alt_ref'], 'acrostic' => ['acrostic']];
    }

    #[DataProvider('nonEmptyColumns')]
    public function testEmptyStringRejected(string $column): void
    {
        $pdo = $this->migrate([['book' => 1, 'chapter' => 1, 'verse' => 1]]);
        try {
            $pdo->exec('UPDATE "ZZ046" SET ' . $column . " = ''");
            self::fail($column . " accepted ''");
        } catch (\PDOException $e) {
            self::assertSame('23514', $e->getCode());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fixtureTables(): array
    {
        return ['TEST1' => ['TEST1'], 'TEST2' => ['TEST2'], 'TEST3' => ['TEST3'], 'VGCL' => ['VGCL'], 'DRB' => ['DRB']];
    }

    #[DataProvider('fixtureTables')]
    public function testFixtureTablesMigrated(string $table): void
    {
        $stmt = $this->getConnection()->prepare('SELECT column_name FROM information_schema.columns WHERE table_name = ?');
        $stmt->execute([$table]);
        $names = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('sort_order', $names);
        self::assertContains('text_tradition', $names);
        self::assertContains('verse_segment', $names);
        self::assertNotContains('verseequiv', $names);
        self::assertNotContains('verseID', $names);
    }

    public function testBookCodes(): void
    {
        $stmt = $this->getConnection()->query('SELECT book, usfm_code, osis_code FROM biblebooks_codes ORDER BY book');
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
        self::assertCount(73, $rows);
        self::assertSame([1, 'GEN', 'Gen'], $rows[0]);
        self::assertSame([19, 'EST', 'Esth'], $rows[18]);
        self::assertSame([73, 'REV', 'Rev'], $rows[72]);
    }
}
