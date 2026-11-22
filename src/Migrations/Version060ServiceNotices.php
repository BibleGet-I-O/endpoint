<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * Service notices, published by the admin and carried in every data-route answer (see the SQL file).
 */
final class Version060ServiceNotices extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'service_notices: notices carried in every data-route answer';
    }

    protected function file(): string
    {
        return '060-service-notices.sql';
    }
}
