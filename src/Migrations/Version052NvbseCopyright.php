<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The first migration run by Doctrine: NVBSE copyrighted by the Libreria Editrice Vaticana, year 1986.
 */
final class Version052NvbseCopyright extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'NVBSE: copyrighted by the Libreria Editrice Vaticana, year 1986 (editio typica altera)';
    }

    protected function file(): string
    {
        return '052-nvbse-copyright.sql';
    }
}
