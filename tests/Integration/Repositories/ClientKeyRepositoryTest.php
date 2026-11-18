<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Repositories;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Repositories\ClientKeyRepository;
use BibleGet\Api\Repositories\InstallationRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;

class ClientKeyRepositoryTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $ids = [];

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $pdo = $this->getConnection();
            foreach ($this->ids as $id) {
                $pdo->prepare('DELETE FROM installations WHERE client_key_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM client_keys WHERE id = ?')->execute([$id]);
            }
        }
        parent::tearDown();
    }

    public function testMintFindListAndRevoke(): void
    {
        $repo        = new ClientKeyRepository($this->getConnection());
        $key         = $repo->mint('google-docs', '2.0.0');
        $this->ids[] = $key['id'];
        ( new InstallationRepository($this->getConnection()) )->register($key['id'], '5d4e0de0-0000-4000-8000-000000000001', '2.0.0', null);

        $active = $repo->findActiveByHash(CredentialFactory::hash($key['plain']));
        self::assertSame(['id' => $key['id'], 'plugin' => 'google-docs', 'max_registrations_per_hour' => 200], $active);

        $found = $repo->find($key['id']);
        self::assertNotNull($found);
        self::assertSame('google-docs', $found['plugin']);
        self::assertSame('2.0.0', $found['plugin_version']);
        self::assertTrue($found['is_active']);
        self::assertSame(200, $found['max_registrations_per_hour']);
        self::assertNull($found['revoked_at']);
        self::assertArrayNotHasKey('key_hash', $found);

        $listed = array_values(array_filter($repo->list(), static fn (array $r): bool => $r['id'] === $key['id']));
        self::assertCount(1, $listed);
        self::assertSame(1, $listed[0]['installations']);
        self::assertTrue($listed[0]['is_active']);
        self::assertSame(array_keys($found), array_keys(array_diff_key($listed[0], ['installations' => 0])), 'find and list show the same columns');

        self::assertTrue($repo->revoke($key['id']));
        self::assertFalse($repo->revoke($key['id']), 'already revoked');
        self::assertNull($repo->findActiveByHash(CredentialFactory::hash($key['plain'])));
        $revoked = $repo->find($key['id']);
        self::assertNotNull($revoked);
        self::assertFalse($revoked['is_active']);
        self::assertNotNull($revoked['revoked_at']);

        self::assertNull($repo->find('00000000-0000-0000-0000-000000000000'));
    }
}
