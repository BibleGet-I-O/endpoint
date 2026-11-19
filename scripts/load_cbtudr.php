<?php

/**
 * Loads a CBTUDR checkout (CatholicOS/cbtudr) into the review store (migration 053): the units, and each
 * edition's links with their status, stretches and confidences (data/maps/<slug>/ and drafts/<slug>/).
 * A link already reviewed is never overwritten, so the script can run again after new drafts.
 *
 *   php scripts/load_cbtudr.php <cbtudr-dir> [--edition <slug>]
 *
 * An edition's CBTUDR slug is its BibleGet sigla in lowercase (vgcl = VGCL).
 *
 * Like the key-minting scripts, it is NOT deployed: run it from a local checkout against the target
 * database through an SSH tunnel, with every DB_* variable and APP_ENV set (see mint-client-key.php).
 * It prints APP_ENV and DB_NAME to stderr before writing; check them.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\BtuRepository;
use BibleGet\Api\Util\EnvLoader;

EnvLoader::create(dirname(__DIR__))->safeLoad();

$dir  = $argv[1] ?? '';
$only = null;
if (($argv[2] ?? '') === '--edition') {
    $only = $argv[3] ?? '';
}
if ($dir === '' || !is_file($dir . '/data/books.json') || $only === '') {
    fwrite(STDERR, 'Usage: php scripts/load_cbtudr.php <cbtudr-dir> [--edition <slug>]' . PHP_EOL);
    exit(2);
}

/** @return array<mixed> */
function readJson(string $path): array
{
    $v = json_decode((string) file_get_contents($path), true);
    if (!is_array($v)) {
        fwrite(STDERR, "Not JSON: $path" . PHP_EOL);
        exit(1);
    }
    return $v;
}

ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
fwrite(STDERR, sprintf('APP_ENV=%s DB_NAME=%s' . PHP_EOL, CredentialFactory::currentEnv(), CredentialFactory::env('DB_NAME') ?? ''));

$editions = readJson($dir . '/data/editions.json')['editions'] ?? [];
$slugs    = array_map(static fn ($e) => is_array($e) && is_string($e['slug'] ?? null) ? $e['slug'] : '', is_array($editions) ? $editions : []);
if ($only !== null && !in_array($only, $slugs, true)) {
    fwrite(STDERR, "No edition '$only' in data/editions.json" . PHP_EOL);
    exit(1);
}

$repo = new BtuRepository(Connection::getConnection());

// Units: the book number is the file name's prefix (01-gn.json), the order is the file's.
$units = [];
foreach (glob($dir . '/data/units/*.json') ?: [] as $file) {
    $book = (int) basename($file);
    $data = readJson($file);
    foreach (is_array($data['units'] ?? null) ? $data['units'] : [] as $ord => $u) {
        if (!is_array($u) || !is_string($u['id'] ?? null)) {
            continue;
        }
        $units[] = [
            'id' => $u['id'], 'book' => $book, 'ord' => (int) $ord,
            'parent' => is_string($u['parent'] ?? null) ? $u['parent'] : null,
            'after' => is_string($u['after'] ?? null) ? $u['after'] : null,
            'nv_omitted' => ($u['nv_omitted'] ?? false) === true,
        ];
    }
}
fwrite(STDERR, sprintf('%d units' . PHP_EOL, $repo->upsertUnits($units)));

foreach (is_array($editions) ? $editions : [] as $ed) {
    $slug = is_array($ed) && is_string($ed['slug'] ?? null) ? $ed['slug'] : '';
    if ($slug === '' || ($only !== null && $slug !== $only)) {
        continue;
    }
    $sigla = strtoupper($slug);
    $total = ['inserted' => 0, 'updated' => 0, 'kept' => 0];
    foreach (glob($dir . '/data/maps/' . $slug . '/*.json') ?: [] as $file) {
        $book   = (int) basename($file);
        $map    = readJson($file);
        $dfile  = $dir . '/drafts/' . $slug . '/' . basename($file);
        $drafts = is_file($dfile) ? readJson($dfile) : [];
        $conf   = is_array($drafts['confidence'] ?? null) ? $drafts['confidence'] : [];
        $stretchOf = [];
        $stretches = [];
        foreach (is_array($drafts['stretches'] ?? null) ? $drafts['stretches'] : [] as $st) {
            if (!is_array($st)) {
                continue;
            }
            $sid = (string) (is_scalar($st['id'] ?? null) ? $st['id'] : '');
            $stretches[] = ['id' => $sid, 'units' => array_values(array_filter(is_array($st['units'] ?? null) ? $st['units'] : [], 'is_string'))];
            foreach (is_array($st['verses'] ?? null) ? $st['verses'] : [] as $ref) {
                if (is_string($ref)) {
                    $stretchOf[$ref] = $sid;
                }
            }
        }
        $links = [];
        foreach (is_array($map['verses'] ?? null) ? $map['verses'] : [] as $ord => $v) {
            if (!is_array($v) || !is_string($v['ref'] ?? null) || ($v['omitted'] ?? false) === true) {
                continue;
            }
            $ref    = $v['ref'];
            $kind   = $v['kind'] ?? 'same';
            $status = $v['status'] ?? 'rule';
            if (!in_array($kind, ['same', 'corresponding'], true) || !in_array($status, ['rule', 'draft', 'reviewed'], true)) {
                fwrite(STDERR, sprintf('%s %s: skipped, kind or status not recognised' . PHP_EOL, basename($file), $ref));
                continue;
            }
            $links[] = [
                'ref' => $ref, 'ord' => (int) $ord,
                'units' => array_values(array_filter(is_array($v['units'] ?? null) ? $v['units'] : [], 'is_string')),
                'kind' => $kind,
                'status' => $status,
                'confidence' => is_numeric($conf[$ref] ?? null) ? (float) $conf[$ref] : null,
                'stretch' => $stretchOf[$ref] ?? null,
            ];
        }
        foreach ($repo->loadEdition($sigla, $book, $links, $stretches) as $k => $n) {
            $total[$k] += $n;
        }
    }
    fwrite(STDERR, sprintf('%s: %d inserted, %d updated, %d reviewed kept' . PHP_EOL, $sigla, $total['inserted'], $total['updated'], $total['kept']));
}
