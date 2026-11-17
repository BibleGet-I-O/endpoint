<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

/**
 * Read-only aggregates for GET /admin/stats.
 */
class StatsRepository
{
    public function __construct(private readonly \PDO $db)
    {
    }

    /**
     * Installations per plugin and version: all ever registered, and those
     * active with a request in the last 30 days.
     *
     * @return list<array{plugin: string, version: ?string, total: int, active_30d: int}>
     */
    public function installations(): array
    {
        $stmt = $this->db->query(
            "SELECT c.plugin, i.plugin_version AS version, count(*) AS total,
                    count(*) FILTER (WHERE i.is_active AND i.last_seen_at >= now() - interval '30 days') AS active_30d
             FROM installations i JOIN client_keys c ON c.id = i.client_key_id
             GROUP BY c.plugin, i.plugin_version
             ORDER BY c.plugin, i.plugin_version"
        );
        $out  = [];
        foreach ($stmt === false ? [] : Cast::rows($stmt->fetchAll()) as $r) {
            $out[] = [
                'plugin'     => Cast::string($r['plugin']),
                'version'    => $r['version'] === null ? null : Cast::string($r['version']),
                'total'      => Cast::int($r['total']),
                'active_30d' => Cast::int($r['active_30d']),
            ];
        }
        return $out;
    }

    /**
     * New registrations per client key per UTC hour, first registered in [$from, $to).
     * Re-registrations update an existing row, so they are not counted.
     *
     * @return list<array{client_key_id: string, hour: string, count: int}>
     */
    public function registrationsPerHour(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT client_key_id,
                    to_char(date_trunc('hour', first_seen_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD\"T\"HH24:00:00\"Z\"') AS hour,
                    count(*) AS count
             FROM installations
             WHERE first_seen_at >= :f AND first_seen_at < :t
             GROUP BY 1, 2
             ORDER BY 2, 1"
        );
        $stmt->execute([':f' => self::timestamp($from), ':t' => self::timestamp($to)]);
        $out = [];
        foreach (Cast::rows($stmt->fetchAll()) as $r) {
            $out[] = [
                'client_key_id' => Cast::string($r['client_key_id']),
                'hour'          => Cast::string($r['hour']),
                'count'         => Cast::int($r['count']),
            ];
        }
        return $out;
    }

    /**
     * usage_daily rows for days in [$fromDay, $toDay], each with a label: the
     * application name for a project key, the plugin and its version for an
     * installation, 'none' for uncredentialed requests ('unknown' when the
     * credential has since been deleted).
     *
     * @return list<array{day: string, credential_type: string, credential_id: string, label: string, route: string, requests: int}>
     */
    public function requests(string $fromDay, string $toDay): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.day, u.credential_type, u.credential_id, u.route, u.requests,
                    CASE u.credential_type
                        WHEN 'none' THEN 'none'
                        WHEN 'project' THEN COALESCE(a.name, 'unknown')
                        ELSE COALESCE(c.plugin || COALESCE(' ' || i.plugin_version, ''), 'unknown')
                    END AS label
             FROM usage_daily u
             LEFT JOIN api_keys k ON u.credential_type = 'project' AND k.id = u.credential_id
             LEFT JOIN applications a ON a.id = k.application_id
             LEFT JOIN installations i ON u.credential_type = 'installation' AND i.id = u.credential_id
             LEFT JOIN client_keys c ON c.id = i.client_key_id
             WHERE u.day >= :f AND u.day <= :t
             ORDER BY u.day, u.credential_type, label, u.route"
        );
        $stmt->execute([':f' => $fromDay, ':t' => $toDay]);
        $out = [];
        foreach (Cast::rows($stmt->fetchAll()) as $r) {
            $out[] = [
                'day'             => Cast::string($r['day']),
                'credential_type' => Cast::string($r['credential_type']),
                'credential_id'   => Cast::string($r['credential_id']),
                'label'           => Cast::string($r['label']),
                'route'           => Cast::string($r['route']),
                'requests'        => Cast::int($r['requests']),
            ];
        }
        return $out;
    }

    private static function timestamp(\DateTimeImmutable $d): string
    {
        return $d->format('Y-m-d\TH:i:s.uP');
    }
}
