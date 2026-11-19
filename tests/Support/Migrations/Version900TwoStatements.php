<?php

declare(strict_types=1);

namespace BibleGet\Tests\Support\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/** A test migration whose SQL file holds two statements, as most real migrations do. */
final class Version900TwoStatements extends SqlFileMigration
{
    protected function file(): string
    {
        return '900-two-statements.sql';
    }

    protected function path(): string
    {
        return __DIR__ . '/sql/' . $this->file();
    }
}
