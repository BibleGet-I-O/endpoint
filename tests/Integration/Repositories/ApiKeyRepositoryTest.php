<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Repositories;

use BibleGet\Api\Auth\CredentialFactory;
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
        try {
            $this->getConnection()->prepare('DELETE FROM applications WHERE id = ?')->execute([$this->appId]);
        } catch (\Throwable) {
        }
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
        $keys->touch($new['id']);
        $keys->touch($new['id']);
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
