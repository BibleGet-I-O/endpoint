<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\ClientKeyRepository;
use BibleGet\Api\Repositories\InstallationRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * POST /installations and PATCH /installations/self, on a server that
 * requires credentials (so a registered token is proven on /quote).
 */
#[Group('slow')]
class InstallationsHttpTest extends ServerTestCase
{
    private const QUOTE = '/v3/quote?query=Genesis1,1&version=TEST1';
    private const UUID  = '5a1c0de0-0000-4000-8000-0000000000a1';

    private static string $rateLimitDir;

    private \PDO $db;
    /** @var list<string> */
    private array $clientKeyIds = [];

    protected static function serverPort(): int
    {
        $envPort = getenv('TEST_SERVER_PORT');
        return ( $envPort !== false && ctype_digit($envPort) ? (int) $envPort : 8199 ) + 2;
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
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->clientKeyIds as $id) {
                $this->db->prepare('DELETE FROM usage_daily WHERE credential_id IN (SELECT id FROM installations WHERE client_key_id = ?)')->execute([$id]);
                $this->db->prepare('DELETE FROM installations WHERE client_key_id = ?')->execute([$id]);
                $this->db->prepare('DELETE FROM client_keys WHERE id = ?')->execute([$id]);
            }
        } catch (\Throwable) {
        }
        Connection::reset();
    }

    /** @return array{plain: string, id: string} */
    private function clientKey(string $plugin = 'wordpress', ?int $cap = null): array
    {
        $key                  = ( new ClientKeyRepository($this->db) )->mint($plugin, '9.9.9');
        $this->clientKeyIds[] = $key['id'];
        if ($cap !== null) {
            $this->db->prepare('UPDATE client_keys SET max_registrations_per_hour = ? WHERE id = ?')->execute([$cap, $key['id']]);
        }
        return $key;
    }

    /** @param array<string, mixed> $body */
    private static function register(string $key, array $body): array
    {
        return self::httpPost('/v3/installations', json_encode($body, JSON_THROW_ON_ERROR), [
            'X-Api-Key'    => $key,
            'Content-Type' => 'application/json',
        ]);
    }

    private function installationCount(string $clientKeyId): int
    {
        $s = $this->db->prepare('SELECT count(*) FROM installations WHERE client_key_id = ?');
        $s->execute([$clientKeyId]);
        return (int) $s->fetchColumn();
    }

    public function testFirstRegistrationIs201AndTokenWorksOnQuote(): void
    {
        $key = $this->clientKey();
        $r   = self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1.2.3']);
        self::assertSame(201, $r['status'], $r['body']);
        $body = self::jsonBody($r['body']);
        self::assertIsString($body['token']);
        self::assertStringStartsWith('bginst_', $body['token']);
        self::assertIsString($body['installation_id']);

        $q = self::httpGet(self::QUOTE, ['X-Api-Key' => $body['token']]);
        self::assertSame(200, $q['status'], $q['body']);
    }

    public function testReRegistrationIs200AndOldTokenStopsWorking(): void
    {
        $key   = $this->clientKey();
        $first = self::jsonBody(self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1.0.0'])['body']);
        $r     = self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1.0.1']);
        self::assertSame(200, $r['status'], $r['body']);
        $second = self::jsonBody($r['body']);
        self::assertSame($first['installation_id'], $second['installation_id']);
        self::assertNotSame($first['token'], $second['token']);

        self::assertSame(401, self::httpGet(self::QUOTE, ['X-Api-Key' => $first['token']])['status']);
        self::assertSame(200, self::httpGet(self::QUOTE, ['X-Api-Key' => $second['token']])['status']);
        self::assertSame(1, $this->installationCount($key['id']));
    }

    public function testRevokedClientKeyIs401(): void
    {
        $key = $this->clientKey();
        ( new ClientKeyRepository($this->db) )->revoke($key['id']);
        $r = self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1']);
        self::assertSame(401, $r['status']);
        self::assertSame(401, self::register('bgclient_test_' . str_repeat('0', 32), ['install_id' => self::UUID, 'plugin_version' => '1'])['status']);
        self::assertSame(401, self::httpPost('/v3/installations', '{}', ['Content-Type' => 'application/json'])['status']);
    }

    public function testInstallationTokenCannotRegister(): void
    {
        $key   = $this->clientKey();
        $token = self::jsonBody(self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1'])['body'])['token'];
        self::assertIsString($token);
        $r = self::register($token, ['install_id' => '5a1c0de0-0000-4000-8000-0000000000a2', 'plugin_version' => '1']);
        self::assertSame(401, $r['status']);
    }

    public function testExistingInstallationsSurviveClientKeyRevocation(): void
    {
        $key   = $this->clientKey();
        $token = self::jsonBody(self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1'])['body'])['token'];
        self::assertIsString($token);
        ( new ClientKeyRepository($this->db) )->revoke($key['id']);
        self::assertSame(200, self::httpGet(self::QUOTE, ['X-Api-Key' => $token])['status']);
    }

    public function testRevokedInstallationReRegistrationIs403(): void
    {
        $key  = $this->clientKey();
        $body = self::jsonBody(self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1'])['body']);
        self::assertIsString($body['installation_id']);
        ( new InstallationRepository($this->db) )->revoke($body['installation_id']);
        self::assertSame(403, self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1'])['status']);
    }

    public function testRegistrationCapIs429WithRetryAfter(): void
    {
        $key = $this->clientKey('wordpress', 1);
        self::assertSame(201, self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1'])['status']);
        $r = self::register($key['plain'], ['install_id' => '5a1c0de0-0000-4000-8000-0000000000a3', 'plugin_version' => '1']);
        self::assertSame(429, $r['status']);
        self::assertGreaterThan(0, (int) ( $r['headers']['retry-after'] ?? 0 ));
    }

    public function testInvalidInstallIdIs422(): void
    {
        $key = $this->clientKey();
        self::assertSame(422, self::register($key['plain'], ['install_id' => 'abc', 'plugin_version' => '1'])['status']);
        self::assertSame(422, self::register($key['plain'], ['plugin_version' => '1'])['status']);
        self::assertSame(422, self::register($key['plain'], ['install_id' => self::UUID])['status']);
        self::assertSame(422, self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => str_repeat('x', 51)])['status']);
        self::assertSame(0, $this->installationCount($key['id']));
    }

    public function testSiteUrlRejectedForNonWordpressPlugin(): void
    {
        $key = $this->clientKey('msword');
        $r   = self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1', 'site_url' => 'https://example.com']);
        self::assertSame(422, $r['status']);

        $wp = $this->clientKey();
        self::assertSame(422, self::register($wp['plain'], ['install_id' => self::UUID, 'plugin_version' => '1', 'site_url' => 'not a url'])['status']);
        self::assertSame(201, self::register($wp['plain'], ['install_id' => self::UUID, 'plugin_version' => '1', 'site_url' => 'https://example.com'])['status']);
    }

    public function testPatchSelfSetsAndClearsContactEmail(): void
    {
        $key   = $this->clientKey();
        $reg   = self::jsonBody(self::register($key['plain'], ['install_id' => self::UUID, 'plugin_version' => '1'])['body']);
        $token = $reg['token'];
        self::assertIsString($token);
        $h = ['X-Api-Key' => $token, 'Content-Type' => 'application/json'];

        $r = self::httpPatch('/v3/installations/self', '{"contact_email":"a@example.com","plugin_version":"2.0.0"}', $h);
        self::assertSame(204, $r['status'], $r['body']);
        $row = $this->db->prepare('SELECT contact_email, plugin_version FROM installations WHERE id = ?');
        $row->execute([$reg['installation_id']]);
        self::assertSame(['contact_email' => 'a@example.com', 'plugin_version' => '2.0.0'], $row->fetch());

        self::assertSame(422, self::httpPatch('/v3/installations/self', '{"contact_email":"nope"}', $h)['status']);

        self::assertSame(204, self::httpPatch('/v3/installations/self', '{"contact_email":""}', $h)['status']);
        $row->execute([$reg['installation_id']]);
        $fetched = $row->fetch();
        self::assertIsArray($fetched);
        self::assertNull($fetched['contact_email']);

        self::assertSame(401, self::httpPatch('/v3/installations/self', '{}', ['X-Api-Key' => $key['plain'], 'Content-Type' => 'application/json'])['status']);
    }

    public function testConcurrentFirstRegistrationYieldsOneRow(): void
    {
        $key  = $this->clientKey();
        $url  = rtrim(self::$baseUrl, '/') . '/v3/installations';
        $body = json_encode(['install_id' => self::UUID, 'plugin_version' => '1'], JSON_THROW_ON_ERROR);
        $mh   = curl_multi_init();
        $hs   = [];
        for ($i = 0; $i < 2; $i++) {
            $ch = curl_init($url);
            self::assertNotFalse($ch);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $key['plain'], 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.1);
            }
        } while ($running > 0);
        $statuses = [];
        foreach ($hs as $ch) {
            $statuses[] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);

        foreach ($statuses as $s) {
            self::assertContains($s, [200, 201]);
        }
        self::assertSame(1, $this->installationCount($key['id']));
    }
}
