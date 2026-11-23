<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * Sirach's prologue as chapter 0, verse 1: index column, insert/split functions, VGCL split (see the SQL file).
 */
final class Version065SirachPrologue extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'Sirach prologue as chapter 0 (functions; VGCL split)';
    }

    protected function file(): string
    {
        return '065-sirach-prologue.sql';
    }
}
