<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 048 (#97): applications, API keys, client keys, installations
 * and usage counters, plus credential columns on the request logs.
 */
final class ApiKeysSchemaTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/048-api-keys-installations.sql';

    protected function tearDown(): void
    {
        $pdo = $this->getConnection();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('DROP TABLE IF EXISTS requests_log__2099');
        parent::tearDown();
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position'
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function newApplication(bool $system): string
    {
        $stmt = $this->getConnection()->prepare(
            "INSERT INTO applications (zitadel_user_id, name, is_system) VALUES ('u', ?, ?) RETURNING id"
        );
        $stmt->execute(['app-' . bin2hex(random_bytes(4)), $system ? 'true' : 'false']);
        return (string) $stmt->fetchColumn();
    }

    private function newClientKey(): string
    {
        $stmt = $this->getConnection()->prepare(
            "INSERT INTO client_keys (plugin, key_hash, key_prefix) VALUES ('wordpress', ?, 'bgclient_x') RETURNING id"
        );
        $stmt->execute([bin2hex(random_bytes(32))]);
        return (string) $stmt->fetchColumn();
    }

    private function expectSqlState(string $state, callable $fn): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $fn();
            self::fail("Expected SQLSTATE $state");
        } catch (\PDOException $e) {
            self::assertSame($state, $e->getCode());
        } finally {
            $pdo->rollBack();
        }
    }

    public function testTablesExist(): void
    {
        self::assertSame(
            [
                'id',
                'zitadel_user_id',
                'name',
                'description',
                'website',
                'status',
                'review_notes',
                'reviewed_by',
                'reviewed_at',
                'is_system',
                'is_active',
                'created_at', 'updated_at'
            ],
            $this->columns('applications')
        );
        self::assertSame(
            [
                'id',
                'application_id',
                'key_hash',
                'key_prefix',
                'name',
                'scope',
                'rate_limit_per_hour',
                'is_active',
                'last_used_at',
                'expires_at', 'created_at'
            ],
            $this->columns('api_keys')
        );
        self::assertSame(
            [
                'id',
                'plugin',
                'plugin_version',
                'key_hash',
                'key_prefix',
                'is_active',
                'created_at',
                'revoked_at', 'max_registrations_per_hour'
            ],
            $this->columns('client_keys')
        );
        self::assertSame(
            [
                'id',
                'client_key_id',
                'install_id',
                'token_hash',
                'token_prefix',
                'plugin_version',
                'site_url',
                'contact_email',
                'rate_limit_per_hour',
                'is_active',
                'first_seen_at',
                'last_seen_at', 'request_count'
            ],
            $this->columns('installations')
        );
        self::assertSame(
            ['day', 'credential_type', 'credential_id', 'route', 'requests'],
            $this->columns('usage_daily')
        );
    }

    public function testScopeReaderOnlyOnSystemApplications(): void
    {
        $pdo    = $this->getConnection();
        $insert = $pdo->prepare(
            "INSERT INTO api_keys (application_id, key_hash, key_prefix, name, scope) VALUES (?, ?, 'bg_x', 'k', 'reader')"
        );
        $pdo->beginTransaction();
        $user = $this->newApplication(false);
        try {
            $insert->execute([$user, bin2hex(random_bytes(32))]);
            self::fail('Expected check_violation');
        } catch (\PDOException $e) {
            self::assertSame('23514', $e->getCode());
        } finally {
            $pdo->rollBack();
        }

        $pdo->beginTransaction();
        $system = $this->newApplication(true);
        self::assertTrue($insert->execute([$system, bin2hex(random_bytes(32))]));
        $pdo->rollBack();
    }

    public function testInstallationUniquePerClientKeyAndInstallId(): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $ck      = $this->newClientKey();
            $install = '11111111-1111-4111-8111-111111111111';
            $stmt    = $pdo->prepare(
                "INSERT INTO installations (client_key_id, install_id, token_hash, token_prefix) VALUES (?, ?, ?, 'bginst_x')"
            );
            $stmt->execute([$ck, $install, bin2hex(random_bytes(32))]);
            try {
                $stmt->execute([$ck, $install, bin2hex(random_bytes(32))]);
                self::fail('Expected unique_violation');
            } catch (\PDOException $e) {
                self::assertSame('23505', $e->getCode());
            }
        } finally {
            $pdo->rollBack();
        }
    }

    public function testPluginCheck(): void
    {
        $this->expectSqlState('23514', fn () => $this->getConnection()->exec(
            "INSERT INTO client_keys (plugin, key_hash, key_prefix) VALUES ('mediawiki', repeat('a', 64), 'bgclient_x')"
        ));
    }

    public function testReaderFullTextDefaultsFalse(): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO versions_available (sigla) VALUES ('ZZ048')");
        $value = $pdo->query("SELECT reader_full_text FROM versions_available WHERE sigla = 'ZZ048'")->fetchColumn();
        $pdo->rollBack();
        self::assertFalse($value);
    }

    public function testYearlyLogTableHasCredentialColumns(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec("SELECT create_yearly_log_table('2099')");
        $pdo->exec(
            "INSERT INTO requests_log__2099 (credential_type, credential_id, \"QUERY\")
             VALUES ('project', gen_random_uuid(), 'q')"
        );
        self::assertContains('credential_type', $this->columns('requests_log__2099'));
        self::assertContains('credential_id', $this->columns('requests_log__2099'));
    }

    public function testExistingYearlyLogTablesGotCredentialColumns(): void
    {
        self::assertContains('credential_type', $this->columns('requests_log__' . date('Y')));
    }

    public function testMigrationIsIdempotent(): void
    {
        $before = [];
        $tables = ['applications', 'api_keys', 'client_keys', 'installations', 'usage_daily', 'requests_log__' . date('Y')];
        foreach ($tables as $t) {
            $before[$t] = $this->columns($t);
        }
        $sql = (string) file_get_contents(self::MIGRATION);
        $this->getConnection()->exec($sql);
        $this->getConnection()->exec($sql);
        foreach ($tables as $t) {
            self::assertSame($before[$t], $this->columns($t), $t);
        }
    }
}
