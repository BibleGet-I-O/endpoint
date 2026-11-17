<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Auth\CredentialType;

class ClientKeyRepository
{
    public function __construct(private readonly \PDO $db)
    {
    }

    /**
     * @return array{id: string, plugin: string, max_registrations_per_hour: int}|null
     */
    public function findActiveByHash(string $hash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, plugin, max_registrations_per_hour FROM client_keys WHERE key_hash = :h AND is_active'
        );
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'id'                         => Cast::string($row['id']),
            'plugin'                     => Cast::string($row['plugin']),
            'max_registrations_per_hour' => Cast::int($row['max_registrations_per_hour']),
        ];
    }

    /**
     * @return array{plain: string, id: string}
     */
    public function mint(string $plugin, string $pluginVersion): array
    {
        $c    = CredentialFactory::generate(CredentialType::Client, CredentialFactory::currentEnv());
        $stmt = $this->db->prepare(
            'INSERT INTO client_keys (plugin, plugin_version, key_hash, key_prefix)
             VALUES (:p, :v, :h, :x) RETURNING id'
        );
        $stmt->execute([':p' => $plugin, ':v' => $pluginVersion, ':h' => $c['hash'], ':x' => $c['prefix']]);
        return ['plain' => $c['plain'], 'id' => Cast::string($stmt->fetchColumn())];
    }

    public function revoke(string $id): void
    {
        $this->db->prepare('UPDATE client_keys SET is_active = FALSE, revoked_at = now() WHERE id = :id')
            ->execute([':id' => $id]);
    }
}
