<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The MediaWiki extension may have client keys and register installations (client_keys.plugin allows 'mediawiki').
 */
final class Version059MediawikiPlugin extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'client_keys.plugin: allow mediawiki';
    }

    protected function file(): string
    {
        return '059-mediawiki-plugin.sql';
    }
}
