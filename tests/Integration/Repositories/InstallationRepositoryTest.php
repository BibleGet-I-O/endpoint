<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Repositories;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Repositories\ClientKeyRepository;
use BibleGet\Api\Repositories\InstallationRepository;
use BibleGet\Api\Repositories\InstallationRevokedException;
use BibleGet\Tests\Integration\DatabaseTestCase;

class InstallationRepositoryTest extends DatabaseTestCase
{
    private string $clientKeyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientKeyId = ( new ClientKeyRepository($this->getConnection()) )->mint('wordpress', '9.9.9')['id'];
    }

    protected function tearDown(): void
    {
        // Cleanup errors are not swallowed: PHPUnit reports them on a passing
        // test (and keeps the original failure otherwise). Nothing to clean
        // when setUp skipped the test for want of a database.
        if ($this->status()->isSkipped()) {
            parent::tearDown();
            return;
        }
        $pdo = $this->getConnection();
        $pdo->prepare('DELETE FROM installations WHERE client_key_id = ?')->execute([$this->clientKeyId]);
        $pdo->prepare('DELETE FROM client_keys WHERE id = ?')->execute([$this->clientKeyId]);
        parent::tearDown();
    }

    private function rowCount(): int
    {
        $s = $this->getConnection()->prepare('SELECT count(*) FROM installations WHERE client_key_id = ?');
        $s->execute([$this->clientKeyId]);
        return (int) $s->fetchColumn();
    }

    public function testRegisterNewThenReRegisterRotatesTokenWithoutNewRow(): void
    {
        $repo = new InstallationRepository($this->getConnection());
        $iid  = '3f2b8c1e-0000-4000-8000-000000000001';

        $first = $repo->register($this->clientKeyId, $iid, '1.0', 'https://a.example');
        self::assertTrue($first['created']);
        $cred = $repo->findActiveByHash(CredentialFactory::hash($first['token']));
        self::assertNotNull($cred);
        self::assertSame(CredentialType::Installation, $cred->type);
        self::assertSame('read', $cred->scope);
        self::assertSame('wordpress', $cred->plugin);
        self::assertSame(300, $cred->ratePerHour);
        self::assertFalse($cred->isSystem);

        $second = $repo->register($this->clientKeyId, $iid, '1.1', null);
        self::assertFalse($second['created']);
        self::assertSame($first['installation_id'], $second['installation_id']);
        self::assertNotSame($first['token'], $second['token']);
        self::assertNotNull($repo->findActiveByHash(CredentialFactory::hash($second['token'])));
        self::assertNull($repo->findActiveByHash(CredentialFactory::hash($first['token'])));
        self::assertSame(1, $this->rowCount());
    }

    public function testRevokeAndTouchThrottle(): void
    {
        $repo = new InstallationRepository($this->getConnection());
        $r    = $repo->register($this->clientKeyId, '3f2b8c1e-0000-4000-8000-000000000002', '1.0', null);
        $repo->updateSelf($r['installation_id'], ['contact_email' => 'a@b.example', 'bogus' => 'x']);
        $this->getConnection()->prepare("UPDATE installations SET last_seen_at = now() - interval '5 minutes' WHERE id = ?")
            ->execute([$r['installation_id']]);
        $repo->touch($r['installation_id']);
        $repo->touch($r['installation_id']);
        $s = $this->getConnection()->prepare('SELECT request_count, contact_email FROM installations WHERE id = ?');
        $s->execute([$r['installation_id']]);
        $row = $s->fetch();
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['request_count']);
        self::assertSame('a@b.example', $row['contact_email']);
        $repo->revoke($r['installation_id']);
        self::assertNull($repo->findActiveByHash(CredentialFactory::hash($r['token'])));
    }

    public function testRevokeRegisteredBetweenOnlyHitsThatWindow(): void
    {
        $repo = new InstallationRepository($this->getConnection());
        $old  = $repo->register($this->clientKeyId, '3f2b8c1e-0000-4000-8000-000000000003', '1.0', null);
        $new  = $repo->register($this->clientKeyId, '3f2b8c1e-0000-4000-8000-000000000004', '1.0', null);
        $this->getConnection()->prepare("UPDATE installations SET first_seen_at = now() - interval '10 days' WHERE id = ?")
            ->execute([$old['installation_id']]);

        $n = $repo->revokeRegisteredBetween(
            $this->clientKeyId,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('+1 hour')
        );
        self::assertSame(1, $n);
        self::assertNotNull($repo->findActiveByHash(CredentialFactory::hash($old['token'])));
        self::assertNull($repo->findActiveByHash(CredentialFactory::hash($new['token'])));
    }

    public function testRevokedClientKeyDoesNotStopExistingInstallation(): void
    {
        $repo = new InstallationRepository($this->getConnection());
        $r    = $repo->register($this->clientKeyId, '3f2b8c1e-0000-4000-8000-000000000005', '1.0', null);
        ( new ClientKeyRepository($this->getConnection()) )->revoke($this->clientKeyId);
        self::assertNotNull($repo->findActiveByHash(CredentialFactory::hash($r['token'])));
    }

    public function testReRegisteringRevokedInstallationIsRefused(): void
    {
        $repo = new InstallationRepository($this->getConnection());
        $iid  = '3f2b8c1e-0000-4000-8000-000000000006';
        $r    = $repo->register($this->clientKeyId, $iid, '1.0', null);
        $repo->revoke($r['installation_id']);
        try {
            $repo->register($this->clientKeyId, $iid, '1.0', null);
            self::fail('Expected InstallationRevokedException');
        } catch (InstallationRevokedException) {
            self::assertNull($repo->findActiveByHash(CredentialFactory::hash($r['token'])));
        }
        self::assertSame(1, $this->rowCount());
        $s = $this->getConnection()->prepare('SELECT is_active, token_hash FROM installations WHERE id = ?');
        $s->execute([$r['installation_id']]);
        $row = $s->fetch();
        self::assertIsArray($row);
        self::assertFalse($row['is_active']);
        self::assertSame(CredentialFactory::hash($r['token']), trim((string) $row['token_hash']));
    }

    public function testRateLimitBelowOneIsRefused(): void
    {
        $repo = new InstallationRepository($this->getConnection());
        $r    = $repo->register($this->clientKeyId, '3f2b8c1e-0000-4000-8000-000000000007', '1.0', null);
        foreach ([0, -5] as $bad) {
            try {
                $repo->setRateLimit($r['installation_id'], $bad);
                self::fail("rate limit {$bad} was accepted");
            } catch (\InvalidArgumentException) {
            }
        }
        // The database refuses it too (migration 048).
        $this->expectException(\PDOException::class);
        $this->getConnection()->prepare('UPDATE installations SET rate_limit_per_hour = 0 WHERE id = ?')->execute([$r['installation_id']]);
    }
}
