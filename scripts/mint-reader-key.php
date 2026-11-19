<?php

/**
 * Mints a `reader` key for the first-party frontend (#97) and prints it once.
 *
 *   php scripts/mint-reader-key.php [key name]
 *
 * Upserts the system application "BibleGet website" (idempotent: it is created
 * once, approved, and reused on later runs; if it exists but is not approved
 * and active the script stops), then adds a new key with scope `reader` and
 * no rate limit. The reader scope is allowed only on is_system applications
 * (database trigger). Run once per environment; the plaintext key goes to
 * stdout and is never stored. To rotate, run again, then revoke the old key
 * with POST /admin/api-keys/{id}/revoke (its id is listed by
 * GET /admin/applications/{application id}).
 *
 * The scripts are NOT deployed (the rsync excludes scripts/, and files outside
 * public/ might be web-reachable): run them from a local checkout against the
 * target database through an SSH tunnel:
 *
 *   ssh -N -L 15432:127.0.0.1:5432 ubuntu@catholicdigitalcommons.org &
 *   export APP_ENV=<the target's: staging | production>
 *   export DB_HOST=127.0.0.1 DB_PORT=15432 DB_NAME=... DB_USER=... DB_PASS=...
 *   php scripts/mint-reader-key.php [key name]
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
use BibleGet\Api\Repositories\ApiKeyRepository;
use BibleGet\Api\Util\EnvLoader;

EnvLoader::create(dirname(__DIR__))->safeLoad();

const SYSTEM_APPLICATION = 'BibleGet website';

// Connection error_log()s the PDO message (host, port, server text); keep it
// off the terminal: on failure the script prints only a generic line.
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

fwrite(STDERR, sprintf('APP_ENV=%s DB_NAME=%s' . PHP_EOL, CredentialFactory::currentEnv(), CredentialFactory::env('DB_NAME') ?? ''));

$db = null;
try {
    $db      = Connection::getConnection();
    $keyName = $argv[1] ?? 'Frontend reader';

    $db->beginTransaction();
    $stmt = $db->prepare('SELECT id, status, is_active FROM applications WHERE is_system AND name = :n');
    $stmt->execute([':n' => SYSTEM_APPLICATION]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $appId    = is_array($existing) ? $existing['id'] : null;
    if (is_array($existing) && ( $existing['status'] !== 'approved' || $existing['is_active'] !== true )) {
        $db->rollBack();
        fwrite(STDERR, 'Failed: the system application "' . SYSTEM_APPLICATION . '" exists but is not approved and active'
            . ' (status ' . ( is_string($existing['status']) ? $existing['status'] : '?' ) . '). Fix it in the database first; no key was minted.' . PHP_EOL);
        exit(1);
    }
    if (!is_string($appId)) {
        $stmt = $db->prepare(
            "INSERT INTO applications (zitadel_user_id, name, description, status, is_system, reviewed_by, reviewed_at)
             VALUES ('system', :n, 'First-party frontend (reader scope)', 'approved', TRUE, 'system', now())
             RETURNING id"
        );
        $stmt->execute([':n' => SYSTEM_APPLICATION]);
        $appId = $stmt->fetchColumn();
    }
    if (!is_string($appId)) {
        throw new RuntimeException('Could not create the system application.');
    }
    $minted = ( new ApiKeyRepository($db) )->mint($appId, $keyName, 'reader', null);
    $db->commit();
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    // Not the exception message: a PDO error can carry the DSN or the query.
    fwrite(STDERR, 'Failed: could not mint the reader key (database error); no key was minted.' . PHP_EOL);
    exit(1);
}

fwrite(STDERR, 'Reader key (shown once, save it now):' . PHP_EOL);
echo $minted['plain'], PHP_EOL;
