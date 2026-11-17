<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Scripts;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * scripts/mint-reader-key.php and scripts/mint-client-key.php, run as a
 * separate process against the test database.
 */
final class MintScriptsTest extends DatabaseTestCase
{
    private const string SYSTEM_APPLICATION = 'BibleGet website';

    /** @var array{id: string, status: string, is_active: bool}|null the system application as found, to restore */
    private ?array $savedSystemApp = null;
    private bool $createdSystemApp = false;

    protected function tearDown(): void
    {
        $db = $this->getConnection();
        if ($this->createdSystemApp) {
            $db->prepare('DELETE FROM applications WHERE is_system AND name = ?')->execute([self::SYSTEM_APPLICATION]);
        } elseif ($this->savedSystemApp !== null) {
            $db->prepare('UPDATE applications SET status = ?, is_active = ? WHERE id = ?')->execute([
                $this->savedSystemApp['status'],
                $this->savedSystemApp['is_active'] ? 'true' : 'false',
                $this->savedSystemApp['id'],
            ]);
        }
        parent::tearDown();
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env  overrides of the process environment
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function runScript(string $script, array $args = [], array $env = []): array
    {
        // The bootstrap loads .env.test into $_ENV only, not into getenv(): pass
        // its effective values explicitly, or a DB_* exported by the shell would
        // reach the child (whose dotenv never overrides the environment).
        $effective = [];
        foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
            if (isset($_ENV[$k]) && is_scalar($_ENV[$k])) {
                $effective[$k] = (string) $_ENV[$k];
            }
        }
        self::assertStringEndsWith('_test', $effective['DB_NAME'] ?? '', 'the scripts must run against the test database');
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/scripts/' . $script], $args);
        $p   = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $effective, $env));
        self::assertIsResource($p);
        $out  = (string) stream_get_contents($pipes[1]);
        $err  = (string) stream_get_contents($pipes[2]);
        $code = proc_close($p);
        return ['code' => $code, 'stdout' => $out, 'stderr' => $err];
    }

    private function readerKeyCount(): int
    {
        return (int) $this->getConnection()->query(
            "SELECT count(*) FROM api_keys k JOIN applications a ON a.id = k.application_id WHERE a.is_system AND a.name = 'BibleGet website'"
        )->fetchColumn();
    }

    public function testReaderKeyRefusesASystemApplicationThatIsNotApproved(): void
    {
        $db   = $this->getConnection();
        $stmt = $db->prepare('SELECT id, status, is_active FROM applications WHERE is_system AND name = ?');
        $stmt->execute([self::SYSTEM_APPLICATION]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $this->savedSystemApp = ['id' => (string) $row['id'], 'status' => (string) $row['status'], 'is_active' => (bool) $row['is_active']];
            $db->prepare("UPDATE applications SET status = 'revoked' WHERE id = ?")->execute([$row['id']]);
        } else {
            $db->prepare(
                "INSERT INTO applications (zitadel_user_id, name, status, is_system) VALUES ('system', ?, 'revoked', TRUE)"
            )->execute([self::SYSTEM_APPLICATION]);
            $this->createdSystemApp = true;
        }
        $before = $this->readerKeyCount();

        $r = self::runScript('mint-reader-key.php');

        self::assertSame(1, $r['code'], $r['stderr']);
        self::assertSame('', $r['stdout']);
        self::assertStringContainsString('not approved and active', $r['stderr']);
        self::assertMatchesRegularExpression('/APP_ENV=\S* DB_NAME=\S+/', $r['stderr']);
        self::assertSame($before, $this->readerKeyCount());
    }

    public function testClientKeyFailurePrintsOnlyAGenericMessage(): void
    {
        $r = self::runScript('mint-client-key.php', ['wordpress', '1.0.0'], ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1']);

        self::assertSame(1, $r['code']);
        self::assertSame('', $r['stdout']);
        self::assertMatchesRegularExpression('/APP_ENV=\S* DB_NAME=\S+/', $r['stderr']);
        self::assertStringNotContainsString('SQLSTATE', $r['stderr']);
        $lines = array_values(array_filter(explode(PHP_EOL, $r['stderr'])));
        self::assertSame('Failed: could not mint the client key (database error); no key was minted.', end($lines));
    }
}
