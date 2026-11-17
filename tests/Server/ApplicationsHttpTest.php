<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\ApplicationRepository;
use BibleGet\Tests\Support\OidcTestIssuer;
use PHPUnit\Framework\Attributes\Group;

/**
 * The developer routes under /applications, on a server configured with a
 * test Zitadel issuer whose JWKS is a local file (ZITADEL_JWKS_FILE).
 */
#[Group('slow')]
class ApplicationsHttpTest extends ServerTestCase
{
    private const QUOTE = '/v3/quote?query=Genesis1,1&version=TEST1';

    private static OidcTestIssuer $issuer;
    private static string $jwksFile;
    private static string $tmpDir;

    private \PDO $db;
    /** @var list<string> */
    private array $subs = [];

    protected static function serverPort(): int
    {
        $envPort = getenv('TEST_SERVER_PORT');
        return ( $envPort !== false && ctype_digit($envPort) ? (int) $envPort : 8199 ) + 3;
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
        self::$tmpDir   = sys_get_temp_dir() . '/bibleget-apps-' . bin2hex(random_bytes(6));
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
            foreach ($this->subs as $sub) {
                $this->db->prepare(
                    'DELETE FROM usage_daily WHERE credential_id IN
                       (SELECT k.id FROM api_keys k JOIN applications a ON a.id = k.application_id WHERE a.zitadel_user_id = ?)'
                )->execute([$sub]);
                $this->db->prepare('DELETE FROM applications WHERE zitadel_user_id = ?')->execute([$sub]);
            }
        } catch (\Throwable) {
        }
        Connection::reset();
    }

    /** @param array<string, mixed> $claims */
    private function bearer(array $claims = []): string
    {
        $sub          = 'test-user-' . bin2hex(random_bytes(6));
        $this->subs[] = $sub;
        return 'Bearer ' . self::$issuer->token($sub, $claims);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function call(string $method, string $path, string $bearer, ?array $body = null): array
    {
        $headers = ['Authorization' => $bearer];
        $json    = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $json                    = json_encode($body, JSON_THROW_ON_ERROR);
        }
        return match ($method) {
            'GET'    => self::httpGet($path, $headers),
            'POST'   => self::httpPost($path, $json, $headers),
            'PATCH'  => self::httpPatch($path, $json, $headers),
            'DELETE' => self::httpDelete($path, $headers),
        };
    }

    /** Creates an application and returns its id. */
    private static function create(string $bearer, string $name = 'My app'): string
    {
        $r = self::call('POST', '/v3/applications', $bearer, ['name' => $name, 'website' => 'https://example.test']);
        self::assertSame(201, $r['status'], $r['body']);
        $app = self::jsonBody($r['body'])['application'] ?? null;
        self::assertIsArray($app);
        self::assertIsString($app['id']);
        return $app['id'];
    }

    public function testMissingOrMalformedBearerIs401(): void
    {
        foreach (
            [
                'no header'  => [],
                'not bearer' => ['Authorization' => 'Basic dXNlcjpwYXNz'],
                'not a jwt'  => ['Authorization' => 'Bearer nope'],
                'api key'    => ['X-Api-Key' => 'bibleget_test_0123456789abcdef0123456789abcdef'],
            ] as $why => $headers
        ) {
            $r = self::httpGet('/v3/applications', $headers);
            self::assertSame(401, $r['status'], $why . ': ' . $r['body']);
            self::assertStringStartsWith('application/problem+json', $r['headers']['content-type'] ?? '', $why);
            self::assertStringStartsWith('Bearer', $r['headers']['www-authenticate'] ?? '', $why);
        }
    }

    public function testWrongIssuerAudienceOrExpiredTokenIs401(): void
    {
        $now = time();
        foreach (
            [
                'wrong issuer'   => ['iss' => 'https://evil.example'],
                'wrong audience' => ['aud' => 'another-project'],
                'expired'        => ['exp' => $now - 3600, 'iat' => $now - 7200, 'nbf' => $now - 7200],
            ] as $why => $claims
        ) {
            $r = self::httpGet('/v3/applications', ['Authorization' => $this->bearer($claims)]);
            self::assertSame(401, $r['status'], $why . ': ' . $r['body']);
        }
    }

    public function testUnverifiedEmailCannotCreateApplication(): void
    {
        $bearer = $this->bearer(['email_verified' => false]);
        $r      = self::call('POST', '/v3/applications', $bearer, ['name' => 'Nope']);
        self::assertSame(403, $r['status'], $r['body']);

        $list = self::call('GET', '/v3/applications', $bearer);
        self::assertSame(200, $list['status'], $list['body']);
        self::assertSame(0, self::jsonBody($list['body'])['total'] ?? null);
    }

    public function testCreateValidatesFields(): void
    {
        $bearer = $this->bearer();
        foreach (
            [
                'no name'          => [],
                'empty name'       => ['name' => ''],
                'long name'        => ['name' => str_repeat('a', 101)],
                'name not string'  => ['name' => ['x']],
                'control char'     => ['name' => "bad\nname"],
                'long description' => ['name' => 'ok', 'description' => str_repeat('d', 1001)],
                'ftp website'      => ['name' => 'ok', 'website' => 'ftp://example.test'],
                'not a url'        => ['name' => 'ok', 'website' => 'example'],
                'long website'     => ['name' => 'ok', 'website' => 'https://example.test/' . str_repeat('p', 240)],
            ] as $why => $body
        ) {
            $r = self::call('POST', '/v3/applications', $bearer, $body);
            self::assertSame(422, $r['status'], $why . ': ' . $r['body']);
        }

        // Invalid UTF-8 never reaches PostgreSQL: json_decode refuses it (400).
        $r = self::httpPost('/v3/applications', "{\"name\":\"\xC3\x28\"}", [
            'Authorization' => $bearer,
            'Content-Type'  => 'application/json',
        ]);
        self::assertSame(400, $r['status'], $r['body']);

        // Fields are read from the JSON body only, never from the query string.
        $r = self::httpPost('/v3/applications?name=FromQuery', null, ['Authorization' => $bearer]);
        self::assertSame(422, $r['status'], $r['body']);
    }

    public function testOwnerSeesOnlyOwnApplications(): void
    {
        $alice    = $this->bearer();
        $bob      = $this->bearer();
        $aliceApp = self::create($alice, 'Alice app');
        self::create($bob, 'Bob app');

        $list = self::jsonBody(self::call('GET', '/v3/applications', $alice)['body']);
        self::assertSame(1, $list['total']);
        self::assertIsArray($list['applications']);
        self::assertIsArray($list['applications'][0]);
        self::assertSame('Alice app', $list['applications'][0]['name']);
        self::assertSame('pending', $list['applications'][0]['status']);
        self::assertArrayNotHasKey('zitadel_user_id', $list['applications'][0]);

        self::assertSame(200, self::call('GET', "/v3/applications/{$aliceApp}", $alice)['status']);

        // Bob cannot see, change, resubmit, delete or list keys of Alice's application: 404, as if absent.
        foreach (
            [
                ['GET', "/v3/applications/{$aliceApp}", null],
                ['PATCH', "/v3/applications/{$aliceApp}", ['name' => 'Hijacked']],
                ['POST', "/v3/applications/{$aliceApp}/resubmit", null],
                ['GET', "/v3/applications/{$aliceApp}/keys", null],
                ['POST', "/v3/applications/{$aliceApp}/keys", null],
                ['DELETE', "/v3/applications/{$aliceApp}", null],
            ] as [$method, $path, $body]
        ) {
            $r = self::call($method, $path, $bob, $body);
            self::assertSame(404, $r['status'], "$method $path: " . $r['body']);
        }
        self::assertSame(404, self::call('GET', '/v3/applications/not-a-uuid', $alice)['status']);
        self::assertSame(404, self::call('GET', '/v3/applications/00000000-0000-4000-8000-000000000000', $alice)['status']);

        $patched = self::call('PATCH', "/v3/applications/{$aliceApp}", $alice, ['name' => 'Renamed', 'description' => 'Hello']);
        self::assertSame(200, $patched['status'], $patched['body']);
        $app = self::jsonBody($patched['body'])['application'];
        self::assertIsArray($app);
        self::assertSame('Renamed', $app['name']);
        self::assertSame('Hello', $app['description']);

        // Resubmit only applies to rejected applications.
        self::assertSame(409, self::call('POST', "/v3/applications/{$aliceApp}/resubmit", $alice)['status']);
        ( new ApplicationRepository($this->db) )->reject($aliceApp, 'admin', 'Need more detail');
        $resub = self::call('POST', "/v3/applications/{$aliceApp}/resubmit", $alice);
        self::assertSame(200, $resub['status'], $resub['body']);
        $resubApp = self::jsonBody($resub['body'])['application'];
        self::assertIsArray($resubApp);
        self::assertSame('pending', $resubApp['status']);

        $del = self::call('DELETE', "/v3/applications/{$aliceApp}", $alice);
        self::assertSame(204, $del['status'], $del['body']);
        self::assertSame(404, self::call('GET', "/v3/applications/{$aliceApp}", $alice)['status']);
    }

    public function testKeyMintingRequiresApproval(): void
    {
        $bearer = $this->bearer();
        $appId  = self::create($bearer);

        $pending = self::call('POST', "/v3/applications/{$appId}/keys", $bearer, ['name' => 'Prod']);
        self::assertSame(409, $pending['status'], $pending['body']);

        ( new ApplicationRepository($this->db) )->approve($appId, 'admin', null);

        $r = self::call('POST', "/v3/applications/{$appId}/keys", $bearer, ['name' => 'Prod', 'scope' => 'reader']);
        self::assertSame(201, $r['status'], $r['body']);
        self::assertSame('no-store', $r['headers']['cache-control'] ?? null);
        $body = self::jsonBody($r['body']);
        self::assertIsString($body['key']);
        self::assertMatchesRegularExpression('/^bibleget_test_[0-9a-f]{32}\z/', $body['key']);
        self::assertIsArray($body['record']);
        self::assertSame('read', $body['record']['scope'], 'scope is always read');
        self::assertSame('Prod', $body['record']['name']);
        self::assertArrayNotHasKey('key_hash', $body['record']);

        $list = self::call('GET', "/v3/applications/{$appId}/keys", $bearer);
        self::assertSame(200, $list['status'], $list['body']);
        self::assertStringNotContainsString($body['key'], $list['body'], 'the plaintext key is shown only once');
        self::assertStringNotContainsString('key_hash', $list['body']);
        $keys = self::jsonBody($list['body'])['keys'];
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        self::assertIsArray($keys[0]);
        self::assertSame(substr($body['key'], 0, 20), $keys[0]['key_prefix']);

        $one = self::call('GET', "/v3/applications/{$appId}", $bearer);
        self::assertStringNotContainsString($body['key'], $one['body']);

        $q = self::httpGet(self::QUOTE, ['X-Api-Key' => $body['key']]);
        self::assertSame(200, $q['status'], $q['body']);
    }

    public function testRotateAndRevokeOwnKey(): void
    {
        $alice  = $this->bearer();
        $bob    = $this->bearer();
        $appId  = self::create($alice);
        $bobApp = self::create($bob);
        $repo   = new ApplicationRepository($this->db);
        $repo->approve($appId, 'admin', null);
        $repo->approve($bobApp, 'admin', null);

        $minted = self::jsonBody(self::call('POST', "/v3/applications/{$appId}/keys", $alice, ['name' => 'k'])['body']);
        self::assertIsArray($minted['record']);
        $keyId = $minted['record']['id'];
        self::assertIsString($keyId);
        self::assertIsString($minted['key']);

        // Bob cannot reach Alice's key, not even through his own approved application.
        foreach (["/v3/applications/{$appId}/keys/{$keyId}", "/v3/applications/{$bobApp}/keys/{$keyId}"] as $path) {
            self::assertSame(404, self::call('POST', $path . '/rotate', $bob)['status'], $path);
            self::assertSame(404, self::call('DELETE', $path, $bob)['status'], $path);
        }

        $rot = self::call('POST', "/v3/applications/{$appId}/keys/{$keyId}/rotate", $alice);
        self::assertSame(200, $rot['status'], $rot['body']);
        self::assertSame('no-store', $rot['headers']['cache-control'] ?? null);
        $rotated = self::jsonBody($rot['body']);
        self::assertIsString($rotated['key']);
        self::assertNotSame($minted['key'], $rotated['key']);
        self::assertIsArray($rotated['record']);
        self::assertSame('k', $rotated['record']['name']);
        $newId = $rotated['record']['id'];
        self::assertIsString($newId);

        self::assertSame(401, self::httpGet(self::QUOTE, ['X-Api-Key' => $minted['key']])['status'], 'old key stops working');
        self::assertSame(200, self::httpGet(self::QUOTE, ['X-Api-Key' => $rotated['key']])['status'], 'new key works');
        self::assertSame(404, self::call('POST', "/v3/applications/{$appId}/keys/{$keyId}/rotate", $alice)['status'], 'a revoked key cannot be rotated');

        $rev = self::call('DELETE', "/v3/applications/{$appId}/keys/{$newId}", $alice);
        self::assertSame(204, $rev['status'], $rev['body']);
        self::assertSame(401, self::httpGet(self::QUOTE, ['X-Api-Key' => $rotated['key']])['status'], 'revoked key stops working');
    }

    public function testUnknownSubRouteOrMethodIsRejected(): void
    {
        $bearer = $this->bearer();
        $appId  = self::create($bearer);
        self::assertSame(404, self::call('GET', "/v3/applications/{$appId}/nope", $bearer)['status']);
        self::assertSame(405, self::call('DELETE', '/v3/applications', $bearer)['status']);
        self::assertSame(405, self::call('PATCH', "/v3/applications/{$appId}/keys", $bearer, ['name' => 'x'])['status']);
    }
}
