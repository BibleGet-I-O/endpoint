<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Repositories;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Repositories\ApiKeyRepository;
use BibleGet\Api\Repositories\ApplicationRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;

class ApiKeyRepositoryTest extends DatabaseTestCase
{
    private string $appId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appId = ( new ApplicationRepository($this->getConnection()) )
            ->create('test-user-repo', 'Repo test app', null, null);
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
        $this->getConnection()->prepare('DELETE FROM applications WHERE id = ?')->execute([$this->appId]);
        parent::tearDown();
    }

    public function testKeyOfPendingOrRevokedApplicationIsNotFound(): void
    {
        $apps = new ApplicationRepository($this->getConnection());
        $keys = new ApiKeyRepository($this->getConnection());
        $k    = $keys->mint($this->appId, 'k1');
        $hash = CredentialFactory::hash($k['plain']);

        self::assertNull($keys->findActiveByHash($hash));
        $apps->approve($this->appId, 'admin', null);
        $cred = $keys->findActiveByHash($hash);
        self::assertNotNull($cred);
        self::assertSame(1000, $cred->ratePerHour);
        self::assertSame($this->appId, $cred->applicationId);
        self::assertFalse($cred->isSystem);

        $this->getConnection()->prepare("UPDATE api_keys SET expires_at = now() - interval '1 day' WHERE id = ?")->execute([$k['id']]);
        self::assertNull($keys->findActiveByHash($hash));
        $this->getConnection()->prepare('UPDATE api_keys SET expires_at = NULL WHERE id = ?')->execute([$k['id']]);
        self::assertNotNull($keys->findActiveByHash($hash));

        $apps->revoke($this->appId, 'admin', 'abuse');
        self::assertNull($keys->findActiveByHash($hash));
    }

    public function testMintForApplicationInChecksTheCurrentStatus(): void
    {
        $apps  = new ApplicationRepository($this->getConnection());
        $keys  = new ApiKeyRepository($this->getConnection());
        $count = fn (): int => (int) $this->getConnection()->query(
            "SELECT count(*) FROM api_keys WHERE application_id = '{$this->appId}'"
        )->fetchColumn();

        self::assertNull($keys->mintForApplicationIn($this->appId, 'k', ['approved']), 'pending');
        $apps->approve($this->appId, 'admin', null);
        self::assertNotNull($keys->mintForApplicationIn($this->appId, 'k', ['approved']));
        self::assertTrue($apps->update($this->appId, 'test-user-repo', ['name' => 'Renamed repo test app']));
        self::assertNull($keys->mintForApplicationIn($this->appId, 'k', ['approved']), 'changes_pending');
        self::assertSame(1, $count(), 'only the approved mint inserted a key');
    }

    /**
     * An edit in flight (row locked, changes_pending not yet committed) must
     * hold the mint back rather than let it insert on the stale status.
     */
    public function testMintForApplicationInWaitsForAnEditInFlight(): void
    {
        ( new ApplicationRepository($this->getConnection()) )->approve($this->appId, 'admin', null);
        $editor = $this->getConnection();
        Connection::reset();
        $minter = Connection::getConnection();
        try {
            $editor->beginTransaction();
            ( new ApplicationRepository($editor) )->update($this->appId, 'test-user-repo', ['name' => 'Edit in flight']);
            $minter->exec("SET lock_timeout = '200ms'");
            try {
                ( new ApiKeyRepository($minter) )->mintForApplicationIn($this->appId, 'k', ['approved']);
                self::fail('Expected the mint to wait for the edit\'s row lock');
            } catch (\PDOException $e) {
                self::assertSame('55P03', $e->getCode(), $e->getMessage());
            }
        } finally {
            if ($editor->inTransaction()) {
                $editor->rollBack();
            }
            Connection::reset();
        }
    }

    public function testRevokingApplicationDeactivatesItsKeys(): void
    {
        $apps = new ApplicationRepository($this->getConnection());
        $keys = new ApiKeyRepository($this->getConnection());
        $apps->approve($this->appId, 'admin', null);
        $keys->mint($this->appId, 'k1');
        $apps->revoke($this->appId, 'admin', null);
        foreach ($keys->listForApplication($this->appId) as $row) {
            self::assertFalse($row['is_active']);
        }
        self::assertCount(1, $keys->listForApplication($this->appId));
    }

    public function testRotateRevokesOldAndReturnsNewWithSameScope(): void
    {
        $apps = new ApplicationRepository($this->getConnection());
        $keys = new ApiKeyRepository($this->getConnection());
        $apps->approve($this->appId, 'admin', null);
        $old = $keys->mint($this->appId, 'k1', 'read', 500);
        $new = $keys->rotate($old['id']);
        self::assertNotNull($new);
        self::assertNull($keys->findActiveByHash(CredentialFactory::hash($old['plain'])));
        $cred = $keys->findActiveByHash(CredentialFactory::hash($new['plain']));
        self::assertNotNull($cred);
        self::assertSame('read', $cred->scope);
        self::assertSame(500, $cred->ratePerHour);
    }

    public function testTouchSetsLastUsedAtAtMostOnceAMinute(): void
    {
        $keys     = new ApiKeyRepository($this->getConnection());
        $k        = $keys->mint($this->appId, 'k1');
        $lastUsed = function () use ($k): mixed {
            $s = $this->getConnection()->prepare('SELECT last_used_at FROM api_keys WHERE id = ?');
            $s->execute([$k['id']]);
            return $s->fetchColumn();
        };
        self::assertNull($lastUsed());

        $keys->touch($k['id']);
        $first = $lastUsed();
        self::assertNotNull($first);
        $keys->touch($k['id']);
        self::assertSame($first, $lastUsed(), 'a second touch within the minute changes nothing');

        $this->getConnection()->prepare("UPDATE api_keys SET last_used_at = now() - interval '2 minutes' WHERE id = ?")->execute([$k['id']]);
        $stale = $lastUsed();
        $keys->touch($k['id']);
        self::assertNotSame($stale, $lastUsed());
    }

    public function testRateLimitBelowOneIsRefused(): void
    {
        $keys = new ApiKeyRepository($this->getConnection());
        foreach ([0, -1] as $bad) {
            try {
                $keys->mint($this->appId, 'k', 'read', $bad);
                self::fail("mint with rate limit {$bad} was accepted");
            } catch (\InvalidArgumentException) {
            }
        }
        $k = $keys->mint($this->appId, 'k1');
        try {
            $keys->setRateLimit($k['id'], 0);
            self::fail('setRateLimit(0) was accepted');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(1000, $keys->listForApplication($this->appId)[0]['rate_limit_per_hour']);
        // The database refuses it too (migration 048).
        $this->expectException(\PDOException::class);
        $this->getConnection()->prepare('UPDATE api_keys SET rate_limit_per_hour = 0 WHERE id = ?')->execute([$k['id']]);
    }

    public function testRotateOfRevokedKeyReturnsNullAndMintsNothing(): void
    {
        $keys = new ApiKeyRepository($this->getConnection());
        $old  = $keys->mint($this->appId, 'k1');
        $keys->revoke($old['id']);
        self::assertNull($keys->rotate($old['id']));
        self::assertCount(1, $keys->listForApplication($this->appId));
    }
}
