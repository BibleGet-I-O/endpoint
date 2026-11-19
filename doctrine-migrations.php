<?php

/**
 * Doctrine Migrations configuration, shared by bin/doctrine-migrations (local) and the deploy-time
 * POST /_ops/migrate (src/Handlers/Ops/MigrateHandler.php).
 *
 * Migrations 001-052 were applied by hand as plain SQL files in migrations/ and are not Doctrine migrations:
 * the runner knows only the classes in src/Migrations, starting with Version052NvbseCopyright.
 */

declare(strict_types=1);

return [
    'table_storage'           => [
        'table_name'                 => 'doctrine_migration_versions',
        'version_column_name'        => 'version',
        'version_column_length'      => 191,
        'executed_at_column_name'    => 'executed_at',
        'execution_time_column_name' => 'execution_time',
    ],
    'migrations_paths'        => [
        'BibleGet\\Api\\Migrations' => __DIR__ . '/src/Migrations',
    ],
    'all_or_nothing'          => true,
    'transactional'           => true,
    'check_database_platform' => true,
    'organize_migrations'     => 'none',
];
