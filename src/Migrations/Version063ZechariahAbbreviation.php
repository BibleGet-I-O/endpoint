<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * "Zec", the New American Bible's abbreviation for Zechariah, in biblebooks_abbr ENGLISH (see the SQL file).
 */
final class Version063ZechariahAbbreviation extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'biblebooks_abbr: Zec (en) for Zechariah';
    }

    protected function file(): string
    {
        return '063-zechariah-abbreviation.sql';
    }
}
