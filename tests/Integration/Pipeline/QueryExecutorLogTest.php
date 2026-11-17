<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Pipeline\QueryExecutor;
use BibleGet\Api\Pipeline\QueryFormulator;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Repositories\ApiKeyRepository;
use BibleGet\Api\Repositories\ApplicationRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * v4 logs who asked by credential, never by IP address or geolocation.
 */
class QueryExecutorLogTest extends DatabaseTestCase
{
    private const int FUTURE_YEAR = 2097;

    private ?string $appId = null;

    protected function tearDown(): void
    {
        // Cleanup errors are not swallowed: PHPUnit reports them on a passing
        // test (and keeps the original failure otherwise). Nothing to clean
        // when setUp skipped the test for want of a database.
        if ($this->status()->isSkipped()) {
            parent::tearDown();
            return;
        }
        $this->getConnection()->exec('DROP TABLE IF EXISTS requests_log__' . self::FUTURE_YEAR);
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CLIENT_IP']);
        if ($this->appId !== null) {
            $this->getConnection()->prepare('DELETE FROM applications WHERE id = ?')->execute([$this->appId]);
        }
        parent::tearDown();
    }

    private function quote(?Credential $credential, string $query = 'Genesis1,1', ?int $logYear = null): void
    {
        $ctx = new QuoteContext(['query' => $query, 'version' => 'TEST1'], null, 'https://example.org', 'GET', $credential);
        $ctx->initialize();
        $ctx->queryStrClean();
        ( new QueryValidator($ctx) )->validateQueries();
        ( new QueryFormulator($ctx) )->formulateSQLQueries();
        ( new QueryExecutor($ctx, $logYear) )->executeSQLQueries();
        self::assertNotEmpty($ctx->results);
    }

    /**
     * @return array<string, mixed>
     */
    private function latestLogRow(): array
    {
        $row = $this->getConnection()
            ->query('SELECT * FROM requests_log__' . date('Y') . ' ORDER BY id DESC LIMIT 1')
            ->fetch();
        self::assertIsArray($row);
        return $row;
    }

    public function testLogRowHasCredentialAndNoIp(): void
    {
        // Whatever the server environment says, none of it reaches the log.
        $_SERVER['REMOTE_ADDR']          = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
        $_SERVER['HTTP_X_REAL_IP']       = '198.51.100.2';
        $_SERVER['HTTP_CLIENT_IP']       = '198.51.100.3';

        $db          = $this->getConnection();
        $apps        = new ApplicationRepository($db);
        $this->appId = $apps->create('query-executor-log-test', 'Log test', null, null);
        $apps->approve($this->appId, 'admin', null);
        $key = ( new ApiKeyRepository($db) )->mint($this->appId, 'log test', 'read', 1000);

        $this->quote(new Credential(CredentialType::Project, $key['id'], 'read', 1000, $this->appId, null, null, false));

        $row = $this->latestLogRow();
        self::assertSame('project', $row['credential_type']);
        self::assertSame($key['id'], $row['credential_id']);
        self::assertNull($row['WHO_IP']);
        // The legacy personal columns are left to their default ('' in production, NULL in the test schema).
        foreach (['WHO_WHERE_JSON', 'HEADERS_JSON', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR', 'APP_ID', 'DOMAIN', 'PLUGINVERSION'] as $column) {
            self::assertSame('', (string) ( $row[$column] ?? '' ), $column);
        }
        self::assertSame('https://example.org', $row['ORIGIN']);
        self::assertSame('Genesis1,1', $row['ORIGINALQUERY']);
        self::assertSame('GET', $row['REQUEST_METHOD']);
        self::assertStringContainsString('SELECT', (string) $row['QUERY']);
    }

    public function testUncredentialedRequestIsLoggedWithoutCredential(): void
    {
        $this->quote(null);

        $row = $this->latestLogRow();
        self::assertNull($row['credential_type']);
        self::assertNull($row['credential_id']);
        self::assertNull($row['WHO_IP']);
    }

    public function testRequestIsServedWithoutRemoteAddr(): void
    {
        // v3 refused a request whose IP it could not read; v4 does not look.
        unset($_SERVER['REMOTE_ADDR']);
        $this->quote(null);
        self::assertNotEmpty($this->latestLogRow());
    }

    public function testMissingYearTableIsCreatedAndTheRowLogged(): void
    {
        // On January 1st nothing has created the new year's table yet.
        $db = $this->getConnection();
        $db->exec('DROP TABLE IF EXISTS requests_log__' . self::FUTURE_YEAR);

        $this->quote(null, 'Genesis1,1', self::FUTURE_YEAR);

        $count = $db->query('SELECT count(*) FROM requests_log__' . self::FUTURE_YEAR)->fetchColumn();
        self::assertSame(1, (int) $count);
    }
}
