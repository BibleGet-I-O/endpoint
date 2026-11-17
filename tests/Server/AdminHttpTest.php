<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\ApiKeyRepository;
use BibleGet\Api\Repositories\ApplicationRepository;
use BibleGet\Api\Repositories\ClientKeyRepository;
use BibleGet\Api\Repositories\InstallationRepository;
use BibleGet\Tests\Support\OidcTestIssuer;
use PHPUnit\Framework\Attributes\Group;

/**
 * The admin routes under /admin (role bibleget_admin), on a server configured
 * with a test Zitadel issuer whose JWKS is a local file (ZITADEL_JWKS_FILE).
 */
#[Group('slow')]
class AdminHttpTest extends ServerTestCase
{
    private const QUOTE  = '/v3/quote?query=Genesis1,1&version=TEST1';
    private const ADMIN  = 'bibleget_admin';
    private const NIL_ID = '00000000-0000-4000-8000-000000000000';

    private static OidcTestIssuer $issuer;
    private static string $jwksFile;
    private static string $tmpDir;

    private \PDO $db;
    /** @var list<string> */
    private array $owners = [];
    /** @var list<string> */
    private array $clientKeyIds = [];

    protected static function serverPort(): int
    {
        $envPort = getenv('TEST_SERVER_PORT');
        return ( $envPort !== false && ctype_digit($envPort) ? (int) $envPort : 8199 ) + 4;
    }

    protected static function serverEnv(): array
    {
        return [
            'ZITADEL_ISSUER'          => OidcTestIssuer::ISSUER,
            'ZITADEL_AUDIENCE'        => OidcTestIssuer::AUDIENCE,
            'ZITADEL_JWKS_FILE'       => self::$jwksFile,
            'JWKS_CACHE_PATH'         => self::$tmpDir . '/jwks.json',
            'RATE_LIMIT_STORAGE_PATH' => self::$tmpDir . '/rate_limits',
            'REQUIRE_CREDENTIALS'     => 'true',
        ];
    }

    public static function setUpBeforeClass(): void
    {
        self::$issuer   = new OidcTestIssuer();
        self::$jwksFile = self::$issuer->writeJwksFile();
        self::$tmpDir   = sys_get_temp_dir() . '/bibleget-admin-' . bin2hex(random_bytes(6));
        mkdir(self::$tmpDir);
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        @unlink(self::$jwksFile);
        foreach (glob(self::$tmpDir . '/rate_limits/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$tmpDir . '/rate_limits');
        @unlink(self::$tmpDir . '/jwks.json');
        @rmdir(self::$tmpDir);
    }

    protected function setUp(): void
    {
        Connection::reset();
        try {
            $this->db = Connection::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Test database not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->clientKeyIds as $id) {
                $this->db->prepare('DELETE FROM usage_daily WHERE credential_id IN (SELECT id FROM installations WHERE client_key_id = ?)')->execute([$id]);
                $this->db->prepare('DELETE FROM installations WHERE client_key_id = ?')->execute([$id]);
                $this->db->prepare('DELETE FROM client_keys WHERE id = ?')->execute([$id]);
            }
            foreach ($this->owners as $owner) {
                $this->db->prepare(
                    'DELETE FROM usage_daily WHERE credential_id IN
                       (SELECT k.id FROM api_keys k JOIN applications a ON a.id = k.application_id WHERE a.zitadel_user_id = ?)'
                )->execute([$owner]);
                $this->db->prepare('DELETE FROM applications WHERE zitadel_user_id = ?')->execute([$owner]);
            }
        } catch (\Throwable) {
        }
        Connection::reset();
    }

    /** @param list<string> $roles */
    private static function bearer(string $sub, array $roles = [self::ADMIN]): string
    {
        return 'Bearer ' . self::$issuer->token($sub, [], $roles);
    }

    private static function admin(): string
    {
        return self::bearer('test-admin-1');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function call(string $method, string $path, ?array $body = null, ?string $bearer = null): array
    {
        $headers = ['Authorization' => $bearer ?? self::admin()];
        $json    = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $json                    = json_encode($body, JSON_THROW_ON_ERROR);
        }
        return match ($method) {
            'GET'   => self::httpGet($path, $headers),
            'POST'  => self::httpPost($path, $json, $headers),
            'PATCH' => self::httpPatch($path, $json, $headers),
        };
    }

    /** A pending application owned by a fresh test user; returns its id. */
    private function application(string $name = 'Admin test app'): string
    {
        $owner          = 'test-owner-' . bin2hex(random_bytes(6));
        $this->owners[] = $owner;
        return ( new ApplicationRepository($this->db) )->create($owner, $name, null, null);
    }

    /** @return array{plain: string, id: string} */
    private function clientKey(string $plugin = 'wordpress', string $version = '9.9.9'): array
    {
        $key                  = ( new ClientKeyRepository($this->db) )->mint($plugin, $version);
        $this->clientKeyIds[] = $key['id'];
        return $key;
    }

    private static function iso(int $offsetSeconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $offsetSeconds);
    }

    private function column(string $sql, string $id): mixed
    {
        $s = $this->db->prepare($sql);
        $s->execute([$id]);
        return $s->fetchColumn();
    }

    /** @return array<string, mixed> */
    private static function okJson(array $r, int $status = 200): array
    {
        self::assertSame($status, $r['status'], $r['body']);
        return self::jsonBody($r['body']);
    }

    public function testNonAdminIs403(): void
    {
        $user = self::bearer('test-plain-user', []);
        foreach (
            [
                ['GET', '/v3/admin/applications', null],
                ['GET', '/v3/admin/client-keys', null],
                ['POST', '/v3/admin/client-keys', ['plugin' => 'wordpress', 'plugin_version' => '1.0']],
                ['GET', '/v3/admin/installations', null],
                ['POST', '/v3/admin/installations/revoke-batch', ['client_key_id' => self::NIL_ID]],
                ['PATCH', '/v3/admin/api-keys/' . self::NIL_ID, ['rate_limit_per_hour' => 5]],
                ['GET', '/v3/admin/stats', null],
                ['GET', '/v3/admin/nope', null],
            ] as [$method, $path, $body]
        ) {
            $r = self::call($method, $path, $body, $user);
            self::assertSame(403, $r['status'], "$method $path: " . $r['body']);
            self::assertStringStartsWith('application/problem+json', $r['headers']['content-type'] ?? '');

            $anon = $method === 'GET' ? self::httpGet($path) : self::httpPost($path, '{}', ['Content-Type' => 'application/json']);
            if ($method !== 'PATCH') {
                self::assertSame(401, $anon['status'], "$method $path without a token");
            }
        }
        self::assertSame(401, self::httpGet('/v3/admin/stats', ['X-Api-Key' => 'bibleget_test_' . str_repeat('0', 32)])['status']);

        // With the role: 200, and unknown admin routes or malformed ids are 404.
        self::assertSame(200, self::call('GET', '/v3/admin/stats')['status']);
        self::assertSame(404, self::call('GET', '/v3/admin/nope')['status']);
        self::assertSame(404, self::call('POST', '/v3/admin/applications/not-a-uuid/approve')['status']);
        self::assertSame(404, self::call('POST', '/v3/admin/applications/' . self::NIL_ID . '-x/approve')['status']);
        self::assertSame(405, self::call('PATCH', '/v3/admin/stats', ['x' => 1])['status']);
    }

    public function testApproveRejectRevokeTransitions(): void
    {
        $appId = $this->application('Transitions app');
        $base  = "/v3/admin/applications/{$appId}";

        $list = self::okJson(self::call('GET', '/v3/admin/applications?status=pending'));
        self::assertIsArray($list['applications']);
        self::assertContains($appId, array_column($list['applications'], 'id'));
        self::assertSame(422, self::call('GET', '/v3/admin/applications?status=bogus')['status']);

        // pending → rejected, with notes and the reviewer's sub.
        $rej = self::okJson(self::call('POST', "$base/reject", ['notes' => 'Tell us more']));
        self::assertIsArray($rej['application']);
        self::assertSame('rejected', $rej['application']['status']);
        self::assertSame('Tell us more', $rej['application']['review_notes']);
        self::assertSame('test-admin-1', $rej['application']['reviewed_by']);

        self::assertSame(409, self::call('POST', "$base/reject")['status'], 'rejected → rejected');
        self::assertSame(409, self::call('POST', "$base/revoke")['status'], 'rejected → revoked');

        // rejected → approved; notes may span lines, and are trimmed before the length check.
        $app = self::okJson(self::call('POST', "$base/approve", ['notes' => "  Thanks.\r\n\tLooks good.\n" . str_repeat('x', 975) . '   ']))['application'];
        self::assertIsArray($app);
        self::assertSame('approved', $app['status']);
        self::assertSame("Thanks.\r\n\tLooks good.\n" . str_repeat('x', 975), $app['review_notes']);
        self::assertSame(409, self::call('POST', "$base/reject")['status'], 'approved → rejected');
        self::assertSame(409, self::call('POST', "$base/approve")['status'], 'approved → approved');

        self::assertSame(422, self::call('POST', "$base/approve", ['notes' => "bad\u{0007}notes"])['status']);
        self::assertSame(422, self::call('POST', "$base/approve", ['notes' => "bad\u{001B}notes"])['status']);
        self::assertSame(404, self::call('POST', '/v3/admin/applications/' . self::NIL_ID . '/approve')['status']);
        self::assertSame(404, self::call('POST', "$base/bless")['status']);

        // The project key works, and its limit can be changed.
        $key = ( new ApiKeyRepository($this->db) )->mint($appId, 'k');
        self::assertSame(200, self::httpGet(self::QUOTE, ['X-Api-Key' => $key['plain']])['status']);
        foreach ([0, -1, 1000001, '50', 1.5, null] as $bad) {
            $r = self::call('PATCH', "/v3/admin/api-keys/{$key['id']}", ['rate_limit_per_hour' => $bad]);
            self::assertSame(422, $r['status'], var_export($bad, true) . ': ' . $r['body']);
        }
        self::assertSame(404, self::call('PATCH', '/v3/admin/api-keys/' . self::NIL_ID, ['rate_limit_per_hour' => 5])['status']);
        self::assertSame(404, self::call('PATCH', '/v3/admin/api-keys/nope', ['rate_limit_per_hour' => 5])['status']);
        $patched = self::okJson(self::call('PATCH', "/v3/admin/api-keys/{$key['id']}", ['rate_limit_per_hour' => 50]));
        self::assertIsArray($patched['key']);
        self::assertSame(50, $patched['key']['rate_limit_per_hour']);
        self::assertArrayNotHasKey('key_hash', $patched['key']);
        self::assertSame(50, (int) $this->column('SELECT rate_limit_per_hour FROM api_keys WHERE id = ?', $key['id']));

        // approved → revoked deactivates the keys; revoked is final.
        $app = self::okJson(self::call('POST', "$base/revoke", ['notes' => 'abuse']))['application'];
        self::assertIsArray($app);
        self::assertSame('revoked', $app['status']);
        self::assertSame(401, self::httpGet(self::QUOTE, ['X-Api-Key' => $key['plain']])['status']);
        foreach (['approve', 'reject', 'revoke'] as $action) {
            self::assertSame(409, self::call('POST', "$base/$action")['status'], "revoked → $action");
        }
    }

    public function testMintClientKeyReturnsPlainOnce(): void
    {
        foreach (
            [
                'no plugin'    => ['plugin_version' => '1.0'],
                'bad plugin'   => ['plugin' => 'vscode', 'plugin_version' => '1.0'],
                'no version'   => ['plugin' => 'wordpress'],
                'bad version'  => ['plugin' => 'wordpress', 'plugin_version' => "1.0\n"],
                'long version' => ['plugin' => 'wordpress', 'plugin_version' => str_repeat('1', 51)],
            ] as $why => $body
        ) {
            self::assertSame(422, self::call('POST', '/v3/admin/client-keys', $body)['status'], $why);
        }
        // Body only: the query string is never read.
        self::assertSame(422, self::call('POST', '/v3/admin/client-keys?plugin=wordpress&plugin_version=1.0')['status']);

        $r = self::call('POST', '/v3/admin/client-keys', ['plugin' => 'google-docs', 'plugin_version' => '3.1.0']);
        self::assertSame(201, $r['status'], $r['body']);
        self::assertSame('no-store', $r['headers']['cache-control'] ?? null);
        $body = self::jsonBody($r['body']);
        self::assertIsString($body['key']);
        self::assertMatchesRegularExpression('/^bgclient_test_[0-9a-f]{32}\z/', $body['key']);
        self::assertIsArray($body['record']);
        $id = $body['record']['id'];
        self::assertIsString($id);
        $this->clientKeyIds[] = $id;
        self::assertSame('google-docs', $body['record']['plugin']);
        self::assertSame('3.1.0', $body['record']['plugin_version']);
        self::assertSame(substr($body['key'], 0, 20), $body['record']['key_prefix']);
        self::assertArrayNotHasKey('key_hash', $body['record']);

        $list = self::call('GET', '/v3/admin/client-keys');
        self::assertSame(200, $list['status'], $list['body']);
        self::assertStringNotContainsString($body['key'], $list['body'], 'the plaintext key is shown only once');
        self::assertStringNotContainsString('key_hash', $list['body']);
        $keys = self::jsonBody($list['body'])['client_keys'];
        self::assertIsArray($keys);
        $mine = array_values(array_filter($keys, static fn ($k) => is_array($k) && ( $k['id'] ?? null ) === $id));
        self::assertCount(1, $mine);
        self::assertIsArray($mine[0]);
        self::assertSame(substr($body['key'], 0, 20), $mine[0]['key_prefix']);

        // The key registers installations until it is revoked.
        $reg = ['install_id' => '7a1c0de0-0000-4000-8000-0000000000b1', 'plugin_version' => '3.1.0'];
        $hdr = ['X-Api-Key' => $body['key'], 'Content-Type' => 'application/json'];
        self::assertSame(201, self::httpPost('/v3/installations', json_encode($reg, JSON_THROW_ON_ERROR), $hdr)['status']);

        self::assertSame(204, self::call('POST', "/v3/admin/client-keys/{$id}/revoke")['status']);
        self::assertSame(409, self::call('POST', "/v3/admin/client-keys/{$id}/revoke")['status'], 'already revoked');
        self::assertSame(404, self::call('POST', '/v3/admin/client-keys/' . self::NIL_ID . '/revoke')['status']);
        self::assertSame(404, self::call('POST', '/v3/admin/client-keys/xyz/revoke')['status']);
        $reg['install_id'] = '7a1c0de0-0000-4000-8000-0000000000b2';
        self::assertSame(401, self::httpPost('/v3/installations', json_encode($reg, JSON_THROW_ON_ERROR), $hdr)['status']);
    }

    public function testBatchRevokeHitsOnlyTheWindow(): void
    {
        $key  = $this->clientKey();
        $repo = new InstallationRepository($this->db);
        $ids  = [];
        foreach (['before' => '3 hours', 'inside' => '90 minutes', 'after' => '0 minutes'] as $name => $ago) {
            $reg        = $repo->register($key['id'], sprintf('8b2c0de0-0000-4000-8000-%012d', count($ids) + 1), '1.0', null);
            $ids[$name] = $reg['installation_id'];
            $this->db->prepare('UPDATE installations SET first_seen_at = now() - ?::interval WHERE id = ?')
                ->execute([$ago, $reg['installation_id']]);
        }

        $from = self::iso(-7200);
        $to   = self::iso(-3600);
        foreach (
            [
                'from after to'  => ['client_key_id' => $key['id'], 'from' => $to, 'to' => $from],
                'from equals to' => ['client_key_id' => $key['id'], 'from' => $from, 'to' => $from],
                'over 31 days'   => ['client_key_id' => $key['id'], 'from' => self::iso(-32 * 86400), 'to' => $to],
                'bad date'       => ['client_key_id' => $key['id'], 'from' => 'yesterday', 'to' => $to],
                'impossible day' => ['client_key_id' => $key['id'], 'from' => '2026-02-31T00:00:00Z', 'to' => $to],
                'no from'        => ['client_key_id' => $key['id'], 'to' => $to],
                'bad key id'     => ['client_key_id' => 'abc', 'from' => $from, 'to' => $to],
            ] as $why => $body
        ) {
            self::assertSame(422, self::call('POST', '/v3/admin/installations/revoke-batch', $body)['status'], $why);
        }
        $unknown = ['client_key_id' => self::NIL_ID, 'from' => $from, 'to' => $to];
        self::assertSame(404, self::call('POST', '/v3/admin/installations/revoke-batch', $unknown)['status']);

        // The admin list shows the window's registrations.
        $q    = '/v3/admin/installations?registered_from=' . rawurlencode($from) . '&registered_to=' . rawurlencode($to);
        $list = self::okJson(self::call('GET', $q));
        self::assertIsArray($list['installations']);
        $listed = array_column($list['installations'], 'id');
        self::assertContains($ids['inside'], $listed);
        self::assertNotContains($ids['before'], $listed);
        self::assertNotContains($ids['after'], $listed);
        self::assertStringNotContainsString('token_hash', json_encode($list, JSON_THROW_ON_ERROR));
        self::assertSame(422, self::call('GET', '/v3/admin/installations?plugin=vscode')['status']);
        self::assertSame(422, self::call('GET', '/v3/admin/installations?active_since=soon')['status']);

        $r = self::okJson(self::call('POST', '/v3/admin/installations/revoke-batch', ['client_key_id' => $key['id'], 'from' => $from, 'to' => $to]));
        self::assertSame(['revoked' => 1], $r);

        $active = fn (string $id): bool => in_array($this->column('SELECT is_active FROM installations WHERE id = ?', $id), [true, 't', 1], true);
        self::assertFalse($active($ids['inside']));
        self::assertTrue($active($ids['before']));
        self::assertTrue($active($ids['after']));

        // Single revoke and per-installation limits.
        self::assertSame(204, self::call('POST', "/v3/admin/installations/{$ids['before']}/revoke")['status']);
        self::assertFalse($active($ids['before']));
        self::assertSame(409, self::call('POST', "/v3/admin/installations/{$ids['before']}/revoke")['status']);
        self::assertSame(404, self::call('POST', '/v3/admin/installations/' . self::NIL_ID . '/revoke')['status']);

        self::assertSame(422, self::call('PATCH', "/v3/admin/installations/{$ids['after']}", ['rate_limit_per_hour' => 0])['status']);
        $p = self::okJson(self::call('PATCH', "/v3/admin/installations/{$ids['after']}", ['rate_limit_per_hour' => 10]));
        self::assertIsArray($p['installation']);
        self::assertSame(10, $p['installation']['rate_limit_per_hour']);
        self::assertSame(10, (int) $this->column('SELECT rate_limit_per_hour FROM installations WHERE id = ?', $ids['after']));
        self::assertSame(404, self::call('PATCH', '/v3/admin/installations/' . self::NIL_ID, ['rate_limit_per_hour' => 10])['status']);
    }

    public function testStatsCountsInstallationsAndRequests(): void
    {
        $version = 'stats-' . bin2hex(random_bytes(4));
        $key     = $this->clientKey('libreoffice', $version);
        $repo    = new InstallationRepository($this->db);
        $inst    = [];
        for ($i = 1; $i <= 3; $i++) {
            $inst[] = $repo->register($key['id'], sprintf('9c3d0de0-0000-4000-8000-%012d', $i), $version, null)['installation_id'];
        }
        $this->db->prepare("UPDATE installations SET first_seen_at = date_trunc('hour', now()) WHERE client_key_id = ?")
            ->execute([$key['id']]);
        $this->db->prepare("UPDATE installations SET last_seen_at = now() - interval '40 days' WHERE id = ?")->execute([$inst[1]]);
        $repo->revoke($inst[2]);

        $appName = 'Stats app ' . bin2hex(random_bytes(3));
        $appId   = $this->application($appName);
        ( new ApplicationRepository($this->db) )->approve($appId, 'admin', null);
        $apiKey  = ( new ApiKeyRepository($this->db) )->mint($appId, 'k');
        $apiKey2 = ( new ApiKeyRepository($this->db) )->mint($appId, 'k2');
        $today   = gmdate('Y-m-d');
        $ins     = $this->db->prepare('INSERT INTO usage_daily (day, credential_type, credential_id, route, requests) VALUES (?, ?, ?, ?, ?)');
        // Two keys of one application, two installations of one plugin version: one row each.
        $ins->execute([$today, 'project', $apiKey['id'], 'quote', 5]);
        $ins->execute([$today, 'project', $apiKey2['id'], 'quote', 3]);
        $ins->execute([$today, 'installation', $inst[0], 'metadata', 7]);
        $ins->execute([$today, 'installation', $inst[1], 'metadata', 2]);

        foreach (['?from=2026-13-01', '?from=' . $today . '&to=2020-01-01', '?to=nope', '?from=2020-01-01&to=2026-01-01'] as $q) {
            self::assertSame(422, self::call('GET', '/v3/admin/stats' . $q)['status'], $q);
        }

        $r = self::call('GET', "/v3/admin/stats?from={$today}&to={$today}");
        self::assertSame('no-store', $r['headers']['cache-control'] ?? null);
        $stats = self::okJson($r);

        self::assertIsArray($stats['installations']);
        $row = array_values(array_filter($stats['installations'], static fn ($x) => is_array($x) && ( $x['version'] ?? null ) === $version));
        self::assertSame([['plugin' => 'libreoffice', 'version' => $version, 'total' => 3, 'active_30d' => 1]], $row);

        self::assertIsArray($stats['registrations_per_hour']);
        $hours = array_values(array_filter($stats['registrations_per_hour'], static fn ($x) => is_array($x) && ( $x['client_key_id'] ?? null ) === $key['id']));
        self::assertCount(1, $hours);
        self::assertIsArray($hours[0]);
        self::assertSame(3, $hours[0]['count']);
        self::assertSame(gmdate('Y-m-d\TH:00:00\Z'), $hours[0]['hour']);

        self::assertIsArray($stats['requests']);
        $project = [];
        $plugin  = [];
        foreach ($stats['requests'] as $x) {
            self::assertIsArray($x);
            self::assertArrayNotHasKey('credential_id', $x);
            if ($x['credential_type'] === 'none') {
                self::assertSame('none', $x['label']);
            }
            if (( $x['application_id'] ?? null ) === $appId) {
                $project[] = $x;
            }
            if (( $x['plugin_version'] ?? null ) === $version) {
                $plugin[] = $x;
            }
        }
        self::assertSame(
            [['day' => $today, 'credential_type' => 'project', 'application_id' => $appId, 'plugin' => null, 'plugin_version' => null, 'label' => $appName, 'route' => 'quote', 'requests' => 8]],
            $project
        );
        self::assertSame(
            [['day' => $today, 'credential_type' => 'installation', 'application_id' => null, 'plugin' => 'libreoffice', 'plugin_version' => $version, 'label' => 'libreoffice ' . $version, 'route' => 'metadata', 'requests' => 9]],
            $plugin
        );
    }
}
