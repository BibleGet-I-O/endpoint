<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * NABRE's garbled ellipses (U+FFFD) become "…" (see the SQL file).
 */
final class Version067NabreEllipsis extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'NABRE: garbled ellipses in 12 verses';
    }

    protected function file(): string
    {
        return '067-nabre-ellipsis.sql';
    }
}
