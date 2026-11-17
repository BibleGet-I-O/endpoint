<?php

/**
 * Mints a `reader` key for the first-party frontend (#97) and prints it once.
 *
 *   php scripts/mint-reader-key.php [key name]
 *
 * Upserts the system application "BibleGet website" (idempotent: it is created
 * once, approved, and reused on later runs), then adds a new key with scope
 * `reader` and no rate limit. The reader scope is allowed only on is_system
 * applications (database trigger). Run once per environment, with the target
 * database in .env / the process environment; the plaintext key goes to
 * stdout and is never stored. To rotate, run again and revoke the old key.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\ApiKeyRepository;
use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__), ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'], false)->safeLoad();

const SYSTEM_APPLICATION = 'BibleGet website';

try {
    $db      = Connection::getConnection();
    $keyName = $argv[1] ?? 'Frontend reader';

    $db->beginTransaction();
    $stmt = $db->prepare('SELECT id FROM applications WHERE is_system AND name = :n');
    $stmt->execute([':n' => SYSTEM_APPLICATION]);
    $appId = $stmt->fetchColumn();
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
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDERR, 'Reader key (shown once, save it now):' . PHP_EOL);
echo $minted['plain'], PHP_EOL;
