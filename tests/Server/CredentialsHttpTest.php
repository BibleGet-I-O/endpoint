<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\ApiKeyRepository;
use BibleGet\Api\Repositories\ApplicationRepository;
use BibleGet\Api\Repositories\ClientKeyRepository;
use BibleGet\Api\Repositories\InstallationRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Data routes on a server started with REQUIRE_CREDENTIALS=true.
 *
 * Runs its own server on a separate port so the shared server used by the
 * other HTTP tests keeps the requirement off.
 */
#[Group('slow')]
class CredentialsHttpTest extends ServerTestCase
{
    private const QUOTE = '/v3/quote?query=Genesis1,1&version=TEST1';

    private static string $rateLimitDir;

    private \PDO $db;
    /** @var list<string> */
    private array $appIds = [];
    /** @var list<string> */
    private array $credentialIds = [];
    private string $clientKeyId;
    private string $clientKey;
    private string $installationToken;
    /** @var list<string> keys minted on the system application by mint-reader-key.php */
    private array $systemKeyIds = [];

    protected static function serverPort(): int
    {
        return self::basePort() + 1;
    }

    protected static function serverEnv(): array
    {
        return ['REQUIRE_CREDENTIALS' => 'true', 'RATE_LIMIT_STORAGE_PATH' => self::$rateLimitDir];
    }

    public static function setUpBeforeClass(): void
    {
        self::$rateLimitDir = sys_get_temp_dir() . '/bibleget-ratelimit-' . bin2hex(random_bytes(6));
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        foreach (glob(self::$rateLimitDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$rateLimitDir);
    }

    protected function setUp(): void
    {
        Connection::reset();
        try {
            $this->db = Connection::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Test database not available: ' . $e->getMessage());
        }
        $client                  = ( new ClientKeyRepository($this->db) )->mint('wordpress', '9.9.9');
        $this->clientKeyId       = $client['id'];
        $this->clientKey         = $client['plain'];
        $inst                    = ( new InstallationRepository($this->db) )
            ->register($this->clientKeyId, '3f2b8c1e-0000-4000-8000-0000000000c3', '9.9.9', null);
        $this->installationToken = $inst['token'];
        $this->credentialIds[]   = $inst['installation_id'];
    }

    protected function tearDown(): void
    {
        // Cleanup errors are not swallowed: PHPUnit reports them on a passing
        // test (and keeps the original failure otherwise). Nothing to clean
        // when setUp skipped the test for want of a database.
        if ($this->status()->isSkipped()) {
            Connection::reset();
            return;
        }
        if ($this->credentialIds !== []) {
            $in = implode(',', array_fill(0, count($this->credentialIds), '?'));
            $this->db->prepare("DELETE FROM usage_daily WHERE credential_id IN ($in)")->execute($this->credentialIds);
        }
        $this->db->prepare('DELETE FROM installations WHERE client_key_id = ?')->execute([$this->clientKeyId]);
        $this->db->prepare('DELETE FROM client_keys WHERE id = ?')->execute([$this->clientKeyId]);
        foreach ($this->appIds as $appId) {
            $this->db->prepare('DELETE FROM applications WHERE id = ?')->execute([$appId]);
        }
        foreach ($this->systemKeyIds as $keyId) {
            $this->db->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$keyId]);
        }
        Connection::reset();
    }

    /**
     * Mints a key with scripts/mint-reader-key.php, as the website's keys are minted, against
     * the test database (the effective .env.test values are passed to the child explicitly,
     * as in MintScriptsTest).
     *
     * @return array{plain: string, id: string}
     */
    private function mintWebsiteKey(string $scope): array
    {
        $env = [];
        foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
            if (isset($_ENV[$k]) && is_scalar($_ENV[$k])) {
                $env[$k] = (string) $_ENV[$k];
            }
        }
        self::assertStringEndsWith('_test', $env['DB_NAME'] ?? '', 'the script must run against the test database');
        $cmd = [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/mint-reader-key.php', '--scope=' . $scope, 'credentials-http-test'];
        $p   = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
        self::assertIsResource($p);
        $plain = trim((string) stream_get_contents($pipes[1]));
        $err   = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($p), $err);

        $stmt = $this->db->query(
            "SELECT k.id FROM api_keys k JOIN applications a ON a.id = k.application_id
             WHERE a.is_system AND a.name = 'BibleGet website' ORDER BY k.created_at DESC, k.id DESC LIMIT 1"
        );
        self::assertNotFalse($stmt);
        $id = $stmt->fetchColumn();
        self::assertIsString($id);
        $this->systemKeyIds[]  = $id;
        $this->credentialIds[] = $id;
        return ['plain' => $plain, 'id' => $id];
    }

    /**
     * @return array{plain: string, id: string, app: string}
     */
    private function projectKey(?int $ratePerHour = 1000): array
    {
        $apps = new ApplicationRepository($this->db);
        $app  = $apps->create('credentials-http-test', 'Credentials HTTP test', null, null);
        $apps->approve($app, 'admin', null);
        $this->appIds[]        = $app;
        $key                   = ( new ApiKeyRepository($this->db) )->mint($app, 'test', 'read', $ratePerHour);
        $this->credentialIds[] = $key['id'];
        return $key + ['app' => $app];
    }

    public function testNoKeyIs401WithLinkToDeveloperPages(): void
    {
        $r = self::httpGet(self::QUOTE);
        self::assertSame(401, $r['status']);
        self::assertStringContainsString('application/problem+json', $r['headers']['content-type']);
        $body = self::jsonBody($r['body']);
        self::assertSame(401, $body['status']);
        self::assertIsString($body['detail']);
        self::assertStringContainsString('X-Api-Key', $body['detail']);
        self::assertSame('https://www.bibleget.io/developers', $body['developers']);
    }

    public function testSuggestNeedsAKey(): void
    {
        self::assertSame(401, self::httpGet('/v3/search/suggest?prefix=lig&version=TEST1')['status']);
        $key = $this->projectKey();
        self::assertSame(200, self::httpGet('/v3/search/suggest?prefix=lig&version=TEST1', ['X-Api-Key' => $key['plain']])['status']);
    }

    public function testCorsPreflightNeedsNoKeyAndAllowsTheHeader(): void
    {
        $r = self::httpOptions('/v3/quote', [
            'Origin'                         => 'https://example.com',
            'Access-Control-Request-Method'  => 'GET',
            'Access-Control-Request-Headers' => 'X-Api-Key',
        ]);
        self::assertSame(204, $r['status']);
        self::assertStringContainsString('x-api-key', strtolower($r['headers']['access-control-allow-headers'] ?? ''));
    }

    public function testProjectKeyAndInstallationTokenAreServed(): void
    {
        $key = $this->projectKey();
        $r   = self::httpGet(self::QUOTE, ['X-Api-Key' => $key['plain']]);
        self::assertSame(200, $r['status'], $r['body']);
        self::assertStringContainsString('beginning', $r['body']);

        $r = self::httpGet(self::QUOTE, ['X-Api-Key' => $this->installationToken]);
        self::assertSame(200, $r['status'], $r['body']);
        self::assertStringContainsString('beginning', $r['body']);
    }

    public function testClientKeyOnDataRouteIs403(): void
    {
        $r = self::httpGet(self::QUOTE, ['X-Api-Key' => $this->clientKey]);
        self::assertSame(403, $r['status']);
        self::assertStringContainsString('application/problem+json', $r['headers']['content-type']);
    }

    public function testKeyOutsideHeaderIsIgnored(): void
    {
        $key = $this->projectKey();
        $r   = self::httpGet(self::QUOTE . '&api_key=' . urlencode($key['plain']));
        self::assertSame(401, $r['status']);

        $r = self::httpGet(self::QUOTE, ['Authorization' => 'Bearer ' . $key['plain']]);
        self::assertSame(401, $r['status']);
    }

    public function testRevokedApplicationKeyFailsImmediately(): void
    {
        $key = $this->projectKey();
        $r   = self::httpGet(self::QUOTE, ['X-Api-Key' => $key['plain']]);
        self::assertSame(200, $r['status']);

        ( new ApplicationRepository($this->db) )->revoke($key['app'], 'admin', 'test');

        $r = self::httpGet(self::QUOTE, ['X-Api-Key' => $key['plain']]);
        self::assertSame(401, $r['status']);
    }

    public function testRateLimitHeadersAnd429(): void
    {
        $key = $this->projectKey(2);
        $r   = self::httpGet(self::QUOTE, ['X-Api-Key' => $key['plain']]);
        self::assertSame(200, $r['status']);
        self::assertSame('2', $r['headers']['x-ratelimit-limit']);
        self::assertSame('1', $r['headers']['x-ratelimit-remaining']);
        self::assertGreaterThan(time(), (int) $r['headers']['x-ratelimit-reset']);

        $r = self::httpGet(self::QUOTE, ['X-Api-Key' => $key['plain']]);
        self::assertSame(200, $r['status']);
        self::assertSame('0', $r['headers']['x-ratelimit-remaining']);

        $r = self::httpGet(self::QUOTE, ['X-Api-Key' => $key['plain']]);
        self::assertSame(429, $r['status']);
        self::assertArrayHasKey('retry-after', $r['headers']);
        self::assertGreaterThan(0, (int) $r['headers']['retry-after']);
        self::assertSame('2', $r['headers']['x-ratelimit-limit'] ?? null);
        self::assertSame('0', $r['headers']['x-ratelimit-remaining'] ?? null);
        self::assertGreaterThan(time(), (int) ( $r['headers']['x-ratelimit-reset'] ?? 0 ));
    }

    public function testUsageDailyCountsEveryDataRoute(): void
    {
        $key = $this->projectKey();
        self::assertSame(200, self::httpGet(self::QUOTE, ['X-Api-Key' => $key['plain']])['status']);
        self::assertSame(200, self::httpGet('/v3/metadata/bibleversions', ['X-Api-Key' => $key['plain']])['status']);

        $s = $this->db->prepare(
            "SELECT route, requests FROM usage_daily
             WHERE credential_type = 'project' AND credential_id = ? AND day = current_date ORDER BY route"
        );
        $s->execute([$key['id']]);
        self::assertSame(
            [['route' => 'metadata', 'requests' => 1], ['route' => 'quote', 'requests' => 1]],
            $s->fetchAll()
        );
    }

    public function testWebsiteReadKeyIsNotRateLimited(): void
    {
        // A NULL rate_limit_per_hour is the default limit, not "unlimited": a project key
        // minted with no limit still counts against 1000 an hour.
        $project = $this->projectKey(null);
        $r       = self::httpGet(self::QUOTE, ['X-Api-Key' => $project['plain']]);
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame('1000', $r['headers']['x-ratelimit-limit'] ?? null);

        // The website's read key is unlimited because it sits on the is_system application:
        // no limit is enforced, so no X-RateLimit-* headers are sent.
        $website = $this->mintWebsiteKey('read');
        for ($i = 0; $i < 3; $i++) {
            $r = self::httpGet(self::QUOTE, ['X-Api-Key' => $website['plain']]);
            self::assertSame(200, $r['status'], $r['body']);
            self::assertArrayNotHasKey('x-ratelimit-limit', $r['headers']);
            self::assertArrayNotHasKey('x-ratelimit-remaining', $r['headers']);
            self::assertArrayNotHasKey('x-ratelimit-reset', $r['headers']);
        }
    }
}
