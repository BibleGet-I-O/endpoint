<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * The drafted, destructive migration docs/future-migrations/049 (#97) on
 * copies of the request-log tables in a scratch schema; the shared real
 * tables are never touched.
 */
final class StripRequestLogPersonalDataTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../docs/future-migrations/049-strip-personal-data-from-request-logs.sql';
    private const string SCHEMA    = 'strip_pd_test';

    private const array PERSONAL = [
        'WHO_IP',
        'WHO_WHERE_JSON',
        'HEADERS_JSON',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];

    private string $originalSearchPath = '';

    /** A yearly log table that exists in public (any year), used as the template and copied under its own name. */
    private string $yearTable = '';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo                      = $this->getConnection();
        $this->originalSearchPath = (string) $pdo->query('SHOW search_path')->fetchColumn();
        $pdo->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        $pdo->exec('CREATE SCHEMA ' . self::SCHEMA);
        $template = $pdo->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename ~ '^requests_log__[0-9]{4}$'"
            . ' ORDER BY tablename DESC LIMIT 1'
        )->fetchColumn();
        if (!is_string($template)) {
            self::markTestSkipped('No requests_log__YYYY table in the test database.');
        }
        $this->yearTable = $template;
        foreach (['requests_log', $this->yearTable] as $t) {
            $pdo->exec(sprintf('CREATE TABLE %s.%s (LIKE public.%s INCLUDING DEFAULTS)', self::SCHEMA, $t, $this->yearTable));
            $pdo->exec(sprintf('CREATE INDEX %s_ip_when ON %s.%s ("WHO_IP", "WHO_WHEN" DESC)', $t, self::SCHEMA, $t));
        }
    }

    protected function tearDown(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec('SET search_path TO ' . $this->originalSearchPath);
        $pdo->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        parent::tearDown();
    }

    private function applyMigration(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec('SET search_path TO ' . self::SCHEMA . ', public');
        $pdo->exec((string) file_get_contents(self::MIGRATION));
    }

    private function seed(string $table): void
    {
        $this->getConnection()->exec(sprintf(
            "INSERT INTO %s.%s (\"WHO_IP\", \"WHO_WHERE_JSON\", \"HEADERS_JSON\", \"ORIGIN\", \"QUERY\",
                \"HTTP_CLIENT_IP\", \"HTTP_X_FORWARDED_FOR\", \"HTTP_X_REAL_IP\", \"REMOTE_ADDR\", \"APP_ID\", \"DOMAIN\")
             VALUES ('203.0.113.7', '{\"city\":\"Rome\"}', '{\"ua\":\"x\"}', 'wp', 'John3:16',
                '203.0.113.7', '203.0.113.8', '203.0.113.9', '203.0.113.7', 'app1', 'example.org')",
            self::SCHEMA,
            $table
        ));
    }

    public function testPersonalColumnsAreNulledAndTheRestIsIntact(): void
    {
        foreach (['requests_log', $this->yearTable] as $t) {
            $this->seed($t);
        }
        $this->applyMigration();

        foreach (['requests_log', $this->yearTable] as $t) {
            $row = $this->getConnection()->query(sprintf('SELECT * FROM %s.%s', self::SCHEMA, $t))->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            foreach (self::PERSONAL as $col) {
                self::assertNull($row[$col], "$t.$col");
            }
            self::assertSame('wp', $row['ORIGIN']);
            self::assertSame('John3:16', $row['QUERY']);
            self::assertSame('app1', $row['APP_ID']);
            self::assertSame('example.org', $row['DOMAIN']);
        }
    }

    public function testIpIndexesAreDropped(): void
    {
        $this->applyMigration();
        $count = $this->getConnection()->query(
            "SELECT count(*) FROM pg_indexes WHERE schemaname = '" . self::SCHEMA . "' AND indexname LIKE '%\\_ip\\_when'"
        )->fetchColumn();
        self::assertSame(0, (int) $count);
    }

    public function testNewYearlyTablesHaveNoIpIndex(): void
    {
        $this->applyMigration();
        $this->getConnection()->exec("SELECT create_yearly_log_table('2098')");
        $count = $this->getConnection()->query(
            "SELECT count(*) FROM pg_indexes WHERE schemaname = '" . self::SCHEMA . "' AND tablename = 'requests_log__2098' AND indexname LIKE '%ip_when'"
        )->fetchColumn();
        self::assertSame(0, (int) $count);
    }

    public function testSecondRunIsANoOp(): void
    {
        $this->seed('requests_log');
        $this->applyMigration();
        $this->applyMigration();
        $row = $this->getConnection()->query(sprintf('SELECT "WHO_IP", "QUERY" FROM %s.requests_log', self::SCHEMA))->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertNull($row['WHO_IP']);
        self::assertSame('John3:16', $row['QUERY']);
    }
}
