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

    /**
     * Updates last_seen_at and request_count at most once a minute, so
     * request_count counts the minutes with at least one request (active
     * minutes), not requests; usage_daily has the request counts.
     */
    public function touch(string $id): void
    {
        $this->db->prepare(
            "UPDATE installations SET last_seen_at = now(), request_count = request_count + 1
             WHERE id = :id AND last_seen_at < now() - interval '1 minute'"
        )->execute([':id' => $id]);
    }

    /** Columns the admin routes show; never token_hash. */
    private const ADMIN_COLUMNS = 'i.id, i.client_key_id, c.plugin, i.plugin_version, i.token_prefix, i.site_url, i.contact_email,
                    i.rate_limit_per_hour, i.is_active, i.first_seen_at, i.last_seen_at, i.request_count';

    /** Revokes an active installation; false when it does not exist or is already revoked. */
    public function revoke(string $id): bool
    {
        $stmt = $this->db->prepare('UPDATE installations SET is_active = FALSE WHERE id = :id AND is_active');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revokes the client key's active installations first registered in [$from, $to).
     */
    public function revokeRegisteredBetween(string $clientKeyId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $stmt = $this->db->prepare(
            'UPDATE installations SET is_active = FALSE
             WHERE client_key_id = :k AND is_active AND first_seen_at >= :f AND first_seen_at < :t'
        );
        $stmt->execute([':k' => $clientKeyId, ':f' => self::timestamp($from), ':t' => self::timestamp($to)]);
        return $stmt->rowCount();
    }

    /**
     * An installation's admin row.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::ADMIN_COLUMNS . ' FROM installations i JOIN client_keys c ON c.id = i.client_key_id WHERE i.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = Cast::row($stmt->fetch());
        return $row === null ? null : self::adminRow($row);
    }

    /**
     * Sets an installation's hourly limit; returns its admin row, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     * @throws \InvalidArgumentException when $perHour is below 1
     */
    public function setRateLimit(string $id, int $perHour): ?array
    {
        ApiKeyRepository::assertRateLimit($perHour);
        $stmt = $this->db->prepare('UPDATE installations SET rate_limit_per_hour = :r WHERE id = :id');
        $stmt->bindValue(':r', $perHour, \PDO::PARAM_INT);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $this->find($id) : null;
    }

    /**
     * Installations for the admin list, most recently registered first.
     *
     * @param array{plugin?: string, version?: string, active_since?: \DateTimeImmutable,
     *              registered_from?: \DateTimeImmutable, registered_to?: \DateTimeImmutable} $filters
     *        registered_from is inclusive, registered_to exclusive
     * @return array{rows: list<array<string, mixed>>, total: int} total counts all matches, rows at most $limit
     */
    public function list(array $filters, int $limit): array
    {
        $where  = ['TRUE'];
        $params = [];
        $conds  = [
            'plugin'          => 'c.plugin = :plugin',
            'version'         => 'i.plugin_version = :version',
            'active_since'    => 'i.is_active AND i.last_seen_at >= :active_since',
            'registered_from' => 'i.first_seen_at >= :registered_from',
            'registered_to'   => 'i.first_seen_at < :registered_to',
        ];
        foreach ($conds as $name => $sql) {
            if (!isset($filters[$name])) {
                continue;
            }
            $v                  = $filters[$name];
            $where[]            = $sql;
            $params[":{$name}"] = $v instanceof \DateTimeImmutable ? self::timestamp($v) : $v;
        }
        $stmt = $this->db->prepare(
            'SELECT ' . self::ADMIN_COLUMNS . ', count(*) OVER () AS total_matches
             FROM installations i JOIN client_keys c ON c.id = i.client_key_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.first_seen_at DESC, i.id LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        $rows  = Cast::rows($stmt->fetchAll());
        $total = $rows === [] ? 0 : Cast::int($rows[0]['total_matches']);
        return [
            'rows'  => array_map(static fn (array $r): array => self::adminRow(array_diff_key($r, ['total_matches' => 0])), $rows),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function adminRow(array $row): array
    {
        $row['is_active']           = Cast::bool($row['is_active']);
        $row['request_count']       = Cast::int($row['request_count']);
        $row['rate_limit_per_hour'] = $row['rate_limit_per_hour'] === null ? null : Cast::int($row['rate_limit_per_hour']);
        return $row;
    }

    private static function timestamp(\DateTimeImmutable $d): string
    {
        return $d->format('Y-m-d\\TH:i:s.uP');
    }
}
