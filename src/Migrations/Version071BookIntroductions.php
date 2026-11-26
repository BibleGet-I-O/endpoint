<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The editions' introductions: each book's and the general ones (see the SQL file).
 */
final class Version071BookIntroductions extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'book_introduction: books\' and general introductions';
    }

    protected function file(): string
    {
        return '071-book-introductions.sql';
    }
}
