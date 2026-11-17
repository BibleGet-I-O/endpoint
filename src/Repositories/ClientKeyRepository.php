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

    private const PUBLIC_COLUMNS = 'id, plugin, plugin_version, key_prefix, is_active, created_at, revoked_at, max_registrations_per_hour';

    /**
     * Revokes an active client key; false when it does not exist or is already revoked.
     * Installations it registered keep working.
     */
    public function revoke(string $id): bool
    {
        $stmt = $this->db->prepare('UPDATE client_keys SET is_active = FALSE, revoked_at = now() WHERE id = :id AND is_active');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * A client key's listing row (never key_hash).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::PUBLIC_COLUMNS . ' FROM client_keys WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return Cast::row($stmt->fetch());
    }

    /**
     * All client keys, newest first, with the number of installations each registered (never key_hash).
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $stmt = $this->db->query(
            'SELECT c.id, c.plugin, c.plugin_version, c.key_prefix, c.is_active, c.created_at, c.revoked_at,
                    c.max_registrations_per_hour,
                    (SELECT count(*) FROM installations i WHERE i.client_key_id = c.id) AS installations
             FROM client_keys c ORDER BY c.created_at DESC'
        );
        if ($stmt === false) {
            return [];
        }
        $rows = Cast::rows($stmt->fetchAll());
        foreach ($rows as &$row) {
            $row['installations']              = Cast::int($row['installations']);
            $row['max_registrations_per_hour'] = Cast::int($row['max_registrations_per_hour']);
            $row['is_active']                  = Cast::bool($row['is_active']);
        }
        return $rows;
    }
}
