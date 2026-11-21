<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The book abbreviations Romanian, Czech, Albanian and Vietnamese citations use, next to the English ones those
 * languages held; tokens another language holds for another book are left out (see the SQL file).
 */
final class Version057MoreLocalizedBookAbbreviations extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'biblebooks_abbr: Romanian, Czech, Albanian and Vietnamese abbreviations (no token shared with another book)';
    }

    protected function file(): string
    {
        return '057-more-localized-book-abbreviations.sql';
    }
}
