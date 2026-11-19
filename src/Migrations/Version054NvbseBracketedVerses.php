<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * NVBSE: Sir 1:20, Ez 40:15 and Act 24:8, held in the verse before behind an omitted verse's bracketed number,
 * are rows of their own; Ez 40:42-43 in printed order (#230). The rows are changed by the private archive's data
 * half; this checks its result and recounts NVBSE_idx.
 */
final class Version054NvbseBracketedVerses extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'NVBSE: Sir 1:20, Ez 40:15, Act 24:8 split from the verse before; Ez 40:42-43 in printed order (checks the NVBSE-vatican data half)';
    }

    protected function file(): string
    {
        return '054-nvbse-bracketed-verses.sql';
    }
}
