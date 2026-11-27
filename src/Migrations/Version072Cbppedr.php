<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The CBPPEDR catalogs of persons, places and events (see the SQL file).
 */
final class Version072Cbppedr extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'bpe_*: CBPPEDR persons, places and events';
    }

    protected function file(): string
    {
        return '072-cbppedr.sql';
    }
}
