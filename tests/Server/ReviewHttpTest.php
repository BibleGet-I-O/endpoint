<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\BtuRepository;
use BibleGet\Tests\Support\OidcTestIssuer;
use PHPUnit\Framework\Attributes\Group;

/**
 * The review routes under /review (role bibleget_reviewer) for CBTUDR unit links, on a server configured with
 * a test Zitadel issuer. The edition and units are synthetic.
 */
#[Group('slow')]
class ReviewHttpTest extends ServerTestCase
{
    private const REVIEWER = 'bibleget_reviewer';
    private const BASE     = '/v3/review';

    private static OidcTestIssuer $issuer;
    private static string $jwksFile;
    private static string $tmpDir;

    private \PDO $db;

    protected static function serverPort(): int
    {
        return self::basePort() + 6;
    }

    protected static function serverEnv(): array
    {
        return [
            'ZITADEL_ISSUER'          => OidcTestIssuer::ISSUER,
            'ZITADEL_AUDIENCE'        => OidcTestIssuer::AUDIENCE,
            'ZITADEL_JWKS_FILE'       => self::$jwksFile,
            'JWKS_CACHE_PATH'         => self::$tmpDir . '/jwks.json',
            'RATE_LIMIT_STORAGE_PATH' => self::$tmpDir . '/rate_limits',
        ];
    }

    public static function setUpBeforeClass(): void
    {
        self::$issuer   = new OidcTestIssuer();
        self::$jwksFile = self::$issuer->writeJwksFile();
        self::$tmpDir   = sys_get_temp_dir() . '/bibleget-review-' . bin2hex(random_bytes(6));
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
        $this->db->exec((string) file_get_contents(__DIR__ . '/../../migrations/053-btu-review-store.sql'));
        $this->db->exec('TRUNCATE btu_unit, btu_stretch, btu_link RESTART IDENTITY');
        $repo = new BtuRepository($this->db);
        $repo->upsertUnits([
            ['id' => 'btu:tb.5.1', 'book' => 17, 'ord' => 0],
            ['id' => 'btu:tb.5.2', 'book' => 17, 'ord' => 1],
            ['id' => 'btu:tb.5.3', 'book' => 17, 'ord' => 2],
            ['id' => 'btu:tb.9.1', 'book' => 17, 'ord' => 3],
        ]);
        $repo->loadEdition('TEST', 17, [
            ['ref' => '5:1', 'ord' => 0, 'units' => ['btu:tb.5.1'], 'kind' => 'same', 'status' => 'rule'],
            ['ref' => '5:2', 'ord' => 1, 'units' => ['btu:tb.5.2'], 'kind' => 'corresponding', 'status' => 'draft', 'confidence' => 0.5, 'stretch' => '1'],
            ['ref' => '5:3', 'ord' => 2, 'units' => ['btu:tb.5.3'], 'kind' => 'corresponding', 'status' => 'draft', 'confidence' => 0.3, 'stretch' => '1'],
            ['ref' => '9:1', 'ord' => 3, 'units' => ['btu:tb.9.1'], 'kind' => 'same', 'status' => 'draft', 'confidence' => 0.9, 'stretch' => '2'],
        ], [['id' => '1', 'units' => ['btu:tb.5.2', 'btu:tb.5.3']], ['id' => '2', 'units' => ['btu:tb.9.1']]]);
    }

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->db->exec('TRUNCATE btu_unit, btu_stretch, btu_link RESTART IDENTITY');
        }
        Connection::reset();
    }

    /**
     * @param list<string> $roles
     * @return array<string, string>
     */
    private static function auth(array $roles = [self::REVIEWER]): array
    {
        return ['Authorization' => 'Bearer ' . self::$issuer->token('reviewer-1', [], $roles)];
    }

    /** @return array<string, string> */
    private static function json(): array
    {
        return self::auth() + ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    private function linkId(string $ref): int
    {
        $stmt = $this->db->prepare("SELECT id FROM btu_link WHERE edition = 'TEST' AND ref = ?");
        $stmt->execute([$ref]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<mixed> */
    private static function body(array $r): array
    {
        return self::jsonBody($r['body']);
    }

    public function testTheRoutesNeedTheReviewerRole(): void
    {
        self::assertSame(401, self::httpGet(self::BASE . '/editions')['status']);
        self::assertSame(403, self::httpGet(self::BASE . '/editions', self::auth(['bibleget_admin']))['status']);
        self::assertSame(200, self::httpGet(self::BASE . '/editions', self::auth())['status']);
    }

    public function testEditionsListsCounts(): void
    {
        $r = self::httpGet(self::BASE . '/editions', self::auth());
        self::assertSame(['editions' => [['edition' => 'TEST', 'draft' => 3, 'reviewed' => 0, 'rule' => 1]]], self::body($r));
    }

    public function testChaptersAreListedLeastConfidentFirst(): void
    {
        $r = self::httpGet(self::BASE . '/editions/TEST/chapters', self::auth());
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame([[17, 5], [17, 9]], array_map(static fn ($c) => [$c['book'], $c['chapter']], self::body($r)['chapters']));
    }

    public function testChapterReturnsLinksAndUnits(): void
    {
        $r    = self::httpGet(self::BASE . '/editions/TEST/books/17/chapters/5', self::auth());
        $body = self::body($r);
        self::assertSame(['5:1', '5:2', '5:3'], array_column($body['links'], 'ref'));
        self::assertSame(['btu:tb.5.2', 'btu:tb.5.3'], array_column($body['units'], 'id'));
        self::assertSame(['5:2', '5:3'], array_column($body['units'], 'ref'));
        self::assertArrayNotHasKey('reviewed_by', $body['links'][0]);
    }

    public function testAcceptMarksReviewed(): void
    {
        $r = self::httpPatch(self::BASE . '/links/' . $this->linkId('5:2'), '{"action":"accept"}', self::json());
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame('reviewed', self::body($r)['status']);
    }

    public function testRelinkWithinTheStretch(): void
    {
        $r = self::httpPatch(self::BASE . '/links/' . $this->linkId('5:2'), '{"action":"relink","units":["btu:tb.5.2","btu:tb.5.3"]}', self::json());
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame(['btu:tb.5.2', 'btu:tb.5.3'], self::body($r)['units']);
    }

    public function testRelinkOutsideTheStretchIs422(): void
    {
        $r = self::httpPatch(self::BASE . '/links/' . $this->linkId('5:2'), '{"action":"relink","units":["btu:tb.9.1"]}', self::json());
        self::assertSame(422, $r['status'], $r['body']);
        self::assertSame('draft', $this->db->query("SELECT status FROM btu_link WHERE ref = '5:2'")?->fetchColumn());
    }

    public function testRuleLinksAreNotReviewed(): void
    {
        foreach (['{"action":"relink","units":["btu:tb.5.1"]}', '{"action":"accept"}', '{"kind":"corresponding"}'] as $body) {
            self::assertSame(422, self::httpPatch(self::BASE . '/links/' . $this->linkId('5:1'), $body, self::json())['status'], $body);
        }
    }

    public function testBadBodiesAre422(): void
    {
        $id = $this->linkId('5:2');
        foreach (['{"action":"merge"}', '{}', '{"action":"accept","kind":"same"}', '{"kind":"similar"}', '{"action":"split","note":""}', '{"action":"relink","units":[]}'] as $body) {
            self::assertSame(422, self::httpPatch(self::BASE . '/links/' . $id, $body, self::json())['status'], $body);
        }
    }

    public function testNoneSplitAndKind(): void
    {
        $id = $this->linkId('5:3');
        self::assertSame([], self::body(self::httpPatch(self::BASE . '/links/' . $id, '{"action":"none"}', self::json()))['units']);
        $split = self::body(self::httpPatch(self::BASE . '/links/' . $this->linkId('9:1'), '{"action":"split","note":"two parts"}', self::json()));
        self::assertSame(['draft', 'two parts'], [$split['status'], $split['note']]);
        $kind = self::body(self::httpPatch(self::BASE . '/links/' . $this->linkId('9:1'), '{"kind":"corresponding"}', self::json()));
        self::assertSame('corresponding', $kind['kind']);
    }

    public function testUnknownLinkIs404(): void
    {
        self::assertSame(404, self::httpPatch(self::BASE . '/links/999999', '{"action":"accept"}', self::json())['status']);
        self::assertSame(404, self::httpPatch(self::BASE . '/links/abc', '{"action":"accept"}', self::json())['status']);
        self::assertSame(404, self::httpGet(self::BASE . '/editions/test/chapters', self::auth())['status']);
    }

    public function testAcceptChapterReturnsCount(): void
    {
        $r = self::httpPost(self::BASE . '/editions/TEST/books/17/chapters/5/accept', null, self::json());
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame(['accepted' => 2], self::body($r));
        self::assertSame('draft', $this->db->query("SELECT status FROM btu_link WHERE ref = '9:1'")?->fetchColumn());
    }
}
