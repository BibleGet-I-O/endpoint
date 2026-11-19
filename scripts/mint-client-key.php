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
 *
 * The scripts are NOT deployed (the rsync excludes scripts/, and files outside
 * public/ might be web-reachable): run them from a local checkout against the
 * target database through an SSH tunnel:
 *
 *   ssh -N -L 15432:127.0.0.1:5432 ubuntu@catholicdigitalcommons.org &
 *   export APP_ENV=<the target's: staging | production>
 *   export DB_HOST=127.0.0.1 DB_PORT=15432 DB_NAME=... DB_USER=... DB_PASS=...
 *   php scripts/mint-client-key.php <plugin> <plugin_version>
 *
 * Variables in the process environment win over the local .env files, so set
 * every DB_* value and APP_ENV (it decides `live` vs `test` keys). The script
 * prints APP_ENV and DB_NAME to stderr before minting; check them.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Handlers\Admin\ClientKeyAdminHandler;
use BibleGet\Api\Repositories\ClientKeyRepository;
use BibleGet\Api\Util\EnvLoader;

EnvLoader::create(dirname(__DIR__))->safeLoad();

$plugin  = $argv[1] ?? '';
$version = $argv[2] ?? '';
if (!in_array($plugin, ClientKeyAdminHandler::PLUGINS, true) || preg_match(ClientKeyAdminHandler::VERSION_PATTERN, $version) !== 1) {
    fwrite(STDERR, 'Usage: php scripts/mint-client-key.php <' . implode('|', ClientKeyAdminHandler::PLUGINS) . '> <plugin_version>' . PHP_EOL);
    exit(2);
}

// Connection error_log()s the PDO message (host, port, server text); keep it
// off the terminal: on failure the script prints only a generic line.
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

fwrite(STDERR, sprintf('APP_ENV=%s DB_NAME=%s' . PHP_EOL, CredentialFactory::currentEnv(), CredentialFactory::env('DB_NAME') ?? ''));

try {
    $minted = ( new ClientKeyRepository(Connection::getConnection()) )->mint($plugin, $version);
} catch (Throwable) {
    // Not the exception message: a PDO error can carry the DSN or the query.
    fwrite(STDERR, 'Failed: could not mint the client key (database error); no key was minted.' . PHP_EOL);
    exit(1);
}

fwrite(STDERR, "Client key for {$plugin} {$version} (shown once, save it now):" . PHP_EOL);
echo $minted['plain'], PHP_EOL;
