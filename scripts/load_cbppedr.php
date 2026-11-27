<?php

/**
 * Loads a CBPPEDR checkout (CatholicOS/cbppedr) into the bpe_* tables (migration 072): every person, place and
 * event, the CBTUDR units where each is attested, and the editions that name an entry in a labelled unit. Every
 * row is replaced in one transaction, and the checkout's commit is recorded in bpe_release.
 *
 *   php scripts/load_cbppedr.php <cbppedr-dir>
 *
 * Like load_cbtudr.php, it is NOT deployed: run it from a local checkout against the target database through an
 * SSH tunnel, with every DB_* variable and APP_ENV set (see mint-client-key.php). It prints APP_ENV and DB_NAME to
 * stderr before writing; check them. Load CBTUDR first: units it does not know are reported.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Cbppedr\CatalogReader;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\EntityRepository;
use BibleGet\Api\Util\EnvLoader;

EnvLoader::create(dirname(__DIR__))->safeLoad();

$dir = $argv[1] ?? '';
if ($dir === '' || !is_file($dir . '/data/persons.json')) {
    fwrite(STDERR, 'Usage: php scripts/load_cbppedr.php <cbppedr-dir>' . PHP_EOL);
    exit(2);
}

$commit = trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' rev-parse HEAD 2>/dev/null'));
if ($commit === '') {
    fwrite(STDERR, "Not a git checkout: $dir" . PHP_EOL);
    exit(1);
}

ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
fwrite(STDERR, sprintf('APP_ENV=%s DB_NAME=%s' . PHP_EOL, CredentialFactory::currentEnv(), CredentialFactory::env('DB_NAME') ?? ''));

$entries = ( new CatalogReader() )->read($dir)['entries'];
$result  = ( new EntityRepository(Connection::getConnection()) )->replaceAll($entries, $commit);

fwrite(STDERR, sprintf(
    'CBPPEDR %s: %d entries, %d attested units, %d labelled units' . PHP_EOL,
    substr($commit, 0, 12),
    $result['entries'],
    $result['attested'],
    $result['labels']
));
if ($result['unknown_units'] !== []) {
    fwrite(STDERR, sprintf('%d units CBTUDR does not know: %s' . PHP_EOL, count($result['unknown_units']), implode(', ', $result['unknown_units'])));
}
