<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The book abbreviations French, German, Portuguese and Spanish citations use, next to the English ones those
 * languages held; tokens another language holds for another book are left out (see the SQL file).
 */
final class Version056LocalizedBookAbbreviations extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'biblebooks_abbr: French, German, Portuguese and Spanish abbreviations (no token shared with another book)';
    }

    protected function file(): string
    {
        return '056-localized-book-abbreviations.sql';
    }
}
