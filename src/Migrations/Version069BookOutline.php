<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The titles of each book with their levels (see the SQL file).
 */
final class Version069BookOutline extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'book_outline: titles with their levels';
    }

    protected function file(): string
    {
        return '069-book-outline.sql';
    }
}
