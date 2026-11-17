<?php

/**
 * Mints a client key for one plugin release (#97) and prints it once; the CLI
 * equivalent of POST /admin/client-keys, for bootstrapping before the
 * frontend exists.
 *
 *   php scripts/mint-client-key.php <plugin> <plugin_version>
 *
 * plugin is one of wordpress, google-docs, libreoffice, openoffice, msword,
 * webword. The key is not secret (it ships in the plugin build) but is shown
 * only once: the database keeps its hash.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Handlers\Admin\ClientKeyAdminHandler;
use BibleGet\Api\Repositories\ClientKeyRepository;
use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__), ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'], false)->safeLoad();

$plugin  = $argv[1] ?? '';
$version = $argv[2] ?? '';
if (!in_array($plugin, ClientKeyAdminHandler::PLUGINS, true) || preg_match(ClientKeyAdminHandler::VERSION_PATTERN, $version) !== 1) {
    fwrite(STDERR, 'Usage: php scripts/mint-client-key.php <' . implode('|', ClientKeyAdminHandler::PLUGINS) . '> <plugin_version>' . PHP_EOL);
    exit(2);
}

try {
    $minted = ( new ClientKeyRepository(Connection::getConnection()) )->mint($plugin, $version);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDERR, "Client key for {$plugin} {$version} (shown once, save it now):" . PHP_EOL);
echo $minted['plain'], PHP_EOL;
