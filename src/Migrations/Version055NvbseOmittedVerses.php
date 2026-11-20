<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * NVBSE: the 33 verses the Nova Vulgata omits, printed as "(n)" after the verse before, are rows of their own with
 * empty text and omitted = true, as in the other editions (045). The rows are changed by the private archive's data
 * half; this checks its result and recounts NVBSE_idx.
 */
final class Version055NvbseOmittedVerses extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'NVBSE: omitted verses as rows of their own (omitted = true), markers out of the verse before (checks the NVBSE-vatican data half)';
    }

    protected function file(): string
    {
        return '055-nvbse-omitted-verses.sql';
    }
}
