<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Migration 044: every edition table records how the edition prints a row
 * (printed_chapter, verse_label) and whether the verse is omitted.
 */
final class PlacementColumnsTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function tables(): array
    {
        return ['TEST1' => ['TEST1'], 'TEST2' => ['TEST2'], 'TEST3' => ['TEST3'], 'VGCL' => ['VGCL'], 'DRB' => ['DRB']];
    }

    #[DataProvider('tables')]
    public function testEditionTablesHavePlacementColumns(string $table): void
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT column_name, data_type, character_maximum_length, is_nullable, column_default '
            . 'FROM information_schema.columns WHERE table_name = ? '
            . "AND column_name IN ('printed_chapter', 'verse_label', 'omitted')"
        );
        $stmt->execute([$table]);
        $cols = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            self::assertIsArray($row);
            $cols[(string) $row['column_name']] = $row;
        }

        self::assertSame('integer', $cols['printed_chapter']['data_type'] ?? null);
        self::assertSame('YES', $cols['printed_chapter']['is_nullable'] ?? null);
        self::assertSame('character varying', $cols['verse_label']['data_type'] ?? null);
        self::assertSame(16, (int) ( $cols['verse_label']['character_maximum_length'] ?? 0 ));
        self::assertSame('boolean', $cols['omitted']['data_type'] ?? null);
        self::assertSame('NO', $cols['omitted']['is_nullable'] ?? null);
        self::assertSame('false', $cols['omitted']['column_default'] ?? null);
    }
}
