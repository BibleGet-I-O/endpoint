<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * VGCL's garbled "œ" (U+009C) is restored (see the SQL file).
 */
final class Version068VgclOe extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'VGCL: garbled œ in 879 verses';
    }

    protected function file(): string
    {
        return '068-vgcl-oe.sql';
    }
}
