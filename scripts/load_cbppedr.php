<?php

/**
 * Loads a CBPPEDR checkout (CatholicOS/cbppedr) into the bpe_* tables (migrations 072, 073): every person, place and
 * event, the CBTUDR units where each is attested (or only mentioned), the editions that name an entry in a labelled
 * unit, where the entries are named in each edition's text (data/mentions/, the curated file over the generated
 * one) and what a card shows of them (data/details.json, when there is one). Every row is replaced in one
 * transaction, and the checkout's commit is recorded in bpe_release; then each person's and place's number of
 * verses in every edition with mentions is counted from its index (bpe_verse_count).
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

$catalog = ( new CatalogReader() )->read($dir);
$result  = ( new EntityRepository(Connection::getConnection()) )->replaceAll($catalog['entries'], $commit, $catalog['mentions'], $catalog['details']);

fwrite(STDERR, sprintf(
    'CBPPEDR %s: %d entries, %d attested units, %d mentioned units, %d labelled units, %d details, %d verse counts' . PHP_EOL,
    substr($commit, 0, 12),
    $result['entries'],
    $result['attested'],
    $result['mentioned'],
    $result['labels'],
    $result['details'],
    $result['verse_counts']
));
if ($result['unknown_units'] !== []) {
    fwrite(STDERR, sprintf('%d units CBTUDR does not know: %s' . PHP_EOL, count($result['unknown_units']), implode(', ', $result['unknown_units'])));
}
if ($result['unknown_details'] !== []) {
    fwrite(STDERR, sprintf('%d details of entries not loaded: %s' . PHP_EOL, count($result['unknown_details']), implode(', ', array_slice($result['unknown_details'], 0, 20))));
}
foreach ($result['mentions'] as $edition => $report) {
    fwrite(STDERR, sprintf(
        '%s mentions: %d loaded, %d stale units, %d units with no row, %d bad checks, %d with unknown ids' . PHP_EOL,
        $edition,
        $report['loaded'],
        count($report['stale']),
        count($report['no_row']),
        count($report['bad_check']),
        count($report['unknown_ids'])
    ));
    foreach (['stale', 'no_row', 'bad_check', 'unknown_ids'] as $list) {
        if ($report[$list] !== []) {
            $more = count($report[$list]) > 20 ? ', …' : '';
            fwrite(STDERR, sprintf('  %s: %s%s' . PHP_EOL, $list, implode(', ', array_slice($report[$list], 0, 20)), $more));
        }
    }
}
