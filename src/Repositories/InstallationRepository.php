<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Auth\CredentialType;

class InstallationRepository
{
    private const UPDATABLE = ['plugin_version', 'site_url', 'contact_email'];

    public function __construct(private readonly \PDO $db)
    {
    }

    public function findActiveByHash(string $hash): ?Credential
    {
        $stmt = $this->db->prepare(
            'SELECT i.id, i.rate_limit_per_hour, c.plugin
             FROM installations i JOIN client_keys c ON c.id = i.client_key_id
             WHERE i.token_hash = :h AND i.is_active'
        );
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return new Credential(
            CredentialType::Installation,
            Cast::string($row['id']),
            'read',
            $row['rate_limit_per_hour'] === null ? null : Cast::int($row['rate_limit_per_hour']),
            null,
            Cast::string($row['id']),
            Cast::string($row['plugin']),
            false
        );
    }

    /**
     * @return array{token: string, installation_id: string, created: bool}
     */
    public function register(string $clientKeyId, string $installId, string $pluginVersion, ?string $siteUrl): array
    {
        $c    = CredentialFactory::generate(CredentialType::Installation, CredentialFactory::currentEnv());
        $stmt = $this->db->prepare(
            'INSERT INTO installations (client_key_id, install_id, token_hash, token_prefix, plugin_version, site_url)
             VALUES (:k, :i, :h, :x, :v, :s)
             ON CONFLICT (client_key_id, install_id) DO UPDATE SET
                 token_hash = EXCLUDED.token_hash,
                 token_prefix = EXCLUDED.token_prefix,
                 plugin_version = EXCLUDED.plugin_version,
                 site_url = COALESCE(EXCLUDED.site_url, installations.site_url),
                 last_seen_at = now()
             WHERE installations.is_active
             RETURNING id, (xmax = 0) AS created'
        );
        $stmt->execute([
            ':k' => $clientKeyId,
            ':i' => $installId,
            ':h' => $c['hash'],
            ':x' => $c['prefix'],
            ':v' => $pluginVersion,
            ':s' => $siteUrl,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new InstallationRevokedException();
        }
        return [
            'token'           => $c['plain'],
            'installation_id' => Cast::string($row['id']),
            'created'         => Cast::bool($row['created']),
        ];
    }

    /**
     * @param array<string, mixed> $fields only plugin_version, site_url, contact_email are applied
     */
    public function updateSelf(string $installationId, array $fields): void
    {
        $sets   = [];
        $params = [':id' => $installationId];
        foreach (self::UPDATABLE as $col) {
            if (array_key_exists($col, $fields)) {
                $v               = $fields[$col];
                $sets[]          = "$col = :$col";
                $params[":$col"] = is_scalar($v) ? (string) $v : null;
            }
        }
        if ($sets === []) {
            return;
        }
        $this->db->prepare('UPDATE installations SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    /** Updates last_seen_at and request_count at most once a minute. */
    public function touch(string $id): void
    {
        $this->db->prepare(
            "UPDATE installations SET last_seen_at = now(), request_count = request_count + 1
             WHERE id = :id AND last_seen_at < now() - interval '1 minute'"
        )->execute([':id' => $id]);
    }

    public function revoke(string $id): void
    {
        $this->db->prepare('UPDATE installations SET is_active = FALSE WHERE id = :id')->execute([':id' => $id]);
    }

    public function revokeRegisteredBetween(string $clientKeyId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $stmt = $this->db->prepare(
            'UPDATE installations SET is_active = FALSE
             WHERE client_key_id = :k AND is_active AND first_seen_at >= :f AND first_seen_at <= :t'
        );
        $stmt->execute([':k' => $clientKeyId, ':f' => $from->format('c'), ':t' => $to->format('c')]);
        return $stmt->rowCount();
    }
}
