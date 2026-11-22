<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The titles and rights holders of NABRE, BLPD and CEI2008 as their agreements state them (see the SQL file).
 */
final class Version062EditionTitlesAndHolders extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'versions_available: NABRE, BLPD and CEI2008 titles and holders as their agreements state them';
    }

    protected function file(): string
    {
        return '062-edition-titles-and-holders.sql';
    }
}
