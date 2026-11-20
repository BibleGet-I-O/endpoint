<?php

/**
 * Mints the key the live test suite (tests/Live, run by the deploy workflow after each staging deploy) sends as
 * X-Api-Key, and prints it once.
 *
 *   php scripts/mint-live-test-key.php
 *
 * The key is an ordinary project key: scope read, the default limit of 1000 requests an hour, on its own
 * application "BibleGet live tests". The application is owned by the non-person id `bibleget-live-tests`, created
 * and approved on the first run, and reused on later ones. Unlike the website's keys it is not exempt from rate
 * limiting, so the suite goes through the limits real clients meet. The plaintext key goes to stdout and is never
 * stored: pipe it straight into the deploy workflow's secret, without showing it:
 *
 *   ... php scripts/mint-live-test-key.php | gh secret set BIBLEGET_LIVE_API_KEY --env staging
 *
 * To rotate, run again, then revoke the old key with POST /admin/api-keys/{id}/revoke.
 *
 * Like the other mint scripts it is NOT deployed: run it against the target database with every DB_* variable and
 * APP_ENV set (APP_ENV decides `live` vs `test` keys). It prints APP_ENV and DB_NAME to stderr first; check them.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\ApiKeyRepository;
use BibleGet\Api\Util\EnvLoader;

EnvLoader::create(dirname(__DIR__))->safeLoad();

const LIVE_TEST_OWNER       = 'bibleget-live-tests';
const LIVE_TEST_APPLICATION = 'BibleGet live tests';

// Connection error_log()s the PDO message (host, port, server text); keep it off the terminal.
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

fwrite(STDERR, sprintf('APP_ENV=%s DB_NAME=%s' . PHP_EOL, CredentialFactory::currentEnv(), CredentialFactory::env('DB_NAME') ?? ''));

if (count($argv) > 1) {
    fwrite(STDERR, 'Failed: no arguments are taken; no key was minted. Usage: php scripts/mint-live-test-key.php' . PHP_EOL);
    exit(1);
}

$db = null;
try {
    $db = Connection::getConnection();
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT id, status, is_active FROM applications WHERE zitadel_user_id = :o AND name = :n AND NOT is_system');
    $stmt->execute([':o' => LIVE_TEST_OWNER, ':n' => LIVE_TEST_APPLICATION]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $appId    = is_array($existing) ? $existing['id'] : null;
    if (is_array($existing) && ( $existing['status'] !== 'approved' || $existing['is_active'] !== true )) {
        $db->rollBack();
        fwrite(STDERR, 'Failed: the application "' . LIVE_TEST_APPLICATION . '" exists but is not approved and active. Fix it first; no key was minted.' . PHP_EOL);
        exit(1);
    }
    if (!is_string($appId)) {
        $stmt = $db->prepare(
            "INSERT INTO applications (zitadel_user_id, name, description, status, reviewed_by, reviewed_at)
             VALUES (:o, :n, 'The live test suite run after each staging deploy (tests/Live)', 'approved', 'system', now())
             RETURNING id"
        );
        $stmt->execute([':o' => LIVE_TEST_OWNER, ':n' => LIVE_TEST_APPLICATION]);
        $appId = $stmt->fetchColumn();
    }
    if (!is_string($appId)) {
        throw new RuntimeException('Could not create the live-test application.');
    }
    $minted = ( new ApiKeyRepository($db) )->mint($appId, 'Live tests', 'read', 1000);
    $db->commit();
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    // Not the exception message: a PDO error can carry the DSN or the query.
    fwrite(STDERR, 'Failed: could not mint the live-test key (database error); no key was minted.' . PHP_EOL);
    exit(1);
}

fwrite(STDERR, 'Live-test key (shown once, save it now):' . PHP_EOL);
echo $minted['plain'], PHP_EOL;
