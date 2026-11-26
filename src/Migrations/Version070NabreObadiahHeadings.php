<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * Obadiah's three section headings in NABRE (see the SQL file).
 */
final class Version070NabreObadiahHeadings extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'NABRE: Obadiah\'s headings';
    }

    protected function file(): string
    {
        return '070-nabre-obadiah-headings.sql';
    }
}
