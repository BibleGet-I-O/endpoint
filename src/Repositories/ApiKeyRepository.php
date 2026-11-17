<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Auth\CredentialType;

class ApiKeyRepository
{
    public function __construct(private readonly \PDO $db)
    {
    }

    public function findActiveByHash(string $hash): ?Credential
    {
        $stmt = $this->db->prepare(
            "SELECT k.id, k.application_id, k.scope, k.rate_limit_per_hour, a.is_system
             FROM api_keys k JOIN applications a ON a.id = k.application_id
             WHERE k.key_hash = :h AND k.is_active
               AND (k.expires_at IS NULL OR k.expires_at > now())
               AND a.status = 'approved' AND a.is_active"
        );
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return new Credential(
            CredentialType::Project,
            Cast::string($row['id']),
            Cast::string($row['scope']),
            $row['rate_limit_per_hour'] === null ? null : Cast::int($row['rate_limit_per_hour']),
            Cast::string($row['application_id']),
            null,
            null,
            Cast::bool($row['is_system'])
        );
    }

    /**
     * @return array{plain: string, id: string}
     */
    public function mint(string $applicationId, string $name, string $scope = 'read', ?int $ratePerHour = 1000): array
    {
        $c    = CredentialFactory::generate(CredentialType::Project, CredentialFactory::currentEnv());
        $stmt = $this->db->prepare(
            'INSERT INTO api_keys (application_id, key_hash, key_prefix, name, scope, rate_limit_per_hour)
             VALUES (:a, :h, :x, :n, :s, :r) RETURNING id'
        );
        $stmt->bindValue(':a', $applicationId);
        $stmt->bindValue(':h', $c['hash']);
        $stmt->bindValue(':x', $c['prefix']);
        $stmt->bindValue(':n', $name);
        $stmt->bindValue(':s', $scope);
        $stmt->bindValue(':r', $ratePerHour, $ratePerHour === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->execute();
        return ['plain' => $c['plain'], 'id' => Cast::string($stmt->fetchColumn())];
    }

    /** @return list<array<string, mixed>> never includes key_hash */
    public function listForApplication(string $applicationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, application_id, key_prefix, name, scope, rate_limit_per_hour, is_active,
                    last_used_at, expires_at, created_at
             FROM api_keys WHERE application_id = :a ORDER BY created_at DESC'
        );
        $stmt->execute([':a' => $applicationId]);
        return Cast::rows($stmt->fetchAll());
    }

    public function revoke(string $id): void
    {
        $this->db->prepare('UPDATE api_keys SET is_active = FALSE WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Revokes a key and mints a replacement with the same application, name, scope and limit.
     *
     * @return array{plain: string, id: string}|null null when the key does not exist
     */
    public function rotate(string $id): ?array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT application_id, name, scope, rate_limit_per_hour FROM api_keys WHERE id = :id AND is_active FOR UPDATE'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                $this->db->rollBack();
                return null;
            }
            $this->revoke($id);
            $new = $this->mint(
                Cast::string($row['application_id']),
                Cast::string($row['name']),
                Cast::string($row['scope']),
                $row['rate_limit_per_hour'] === null ? null : Cast::int($row['rate_limit_per_hour'])
            );
            $this->db->commit();
            return $new;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Sets a key's hourly limit; returns its listing row (never key_hash), or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function setRateLimit(string $id, int $perHour): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE api_keys SET rate_limit_per_hour = :r WHERE id = :id
             RETURNING id, application_id, key_prefix, name, scope, rate_limit_per_hour, is_active,
                       last_used_at, expires_at, created_at'
        );
        $stmt->bindValue(':r', $perHour, \PDO::PARAM_INT);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        return Cast::row($stmt->fetch());
    }

    /** Updates last_used_at at most once a minute. */
    public function touch(string $id): void
    {
        $this->db->prepare(
            "UPDATE api_keys SET last_used_at = now()
             WHERE id = :id AND (last_used_at IS NULL OR last_used_at < now() - interval '1 minute')"
        )->execute([':id' => $id]);
    }
}
