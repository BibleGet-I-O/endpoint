<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Util\StringUtils;

/**
 * Service notices: what BibleGet tells the clients using it (a breaking change ahead, a release to upgrade to),
 * published by the admin (/admin/service-notices, table service_notices, migration 060) and carried in every
 * data-route answer, so a registered plugin installation, which has no developer account to write to, can show its
 * admins. A notice may target a credential type, a plugin, or that plugin's versions below one; its `id` is stable,
 * so a client shows each notice once. Its deprecation and sunset dates become the RFC 9745 `Deprecation` and
 * RFC 8594 `Sunset` headers, with `Link` to the notice's page.
 *
 * @phpstan-type Notice array{id: string, message: string, link: string|null, published: string, deprecation: string|null, sunset: string|null}
 */
final class ServiceNotices
{
    /**
     * The notices for this request, in their public shape (newest first). Without a database or a table yet, none:
     * a notice must never break a quote.
     *
     * @return list<Notice>
     */
    public static function load(\PDO $pdo, ?Credential $credential): array
    {
        try {
            $stmt = $pdo->query(
                'SELECT * FROM service_notices
                  WHERE published_at <= now() AND (expires_at IS NULL OR expires_at > now())
                  ORDER BY published_at DESC, id'
            );
            $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                return [];
            }
            $version   = null;
            $versioned = false;
            foreach ($rows as $r) {
                $versioned = $versioned || ( is_array($r) && ( $r['target_below_version'] ?? null ) !== null );
            }
            if ($credential?->installationId !== null && $versioned) {
                $v = $pdo->prepare('SELECT plugin_version FROM installations WHERE id = ?');
                $v->execute([$credential->installationId]);
                $found   = $v->fetchColumn();
                $version = is_string($found) && $found !== '' ? $found : null;
            }
            /** @var list<array<string, mixed>> $rows */
            return self::applicable($rows, $credential, $version);
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * The rows (service_notices columns) this credential is targeted by, in their public shape. An installation
     * whose version is unknown is counted as older than any version a notice names.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<Notice>
     */
    public static function applicable(array $rows, ?Credential $credential, ?string $installationVersion): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (self::targets($row, $credential, $installationVersion)) {
                $out[] = [
                    'id'          => StringUtils::asString($row['id'] ?? ''),
                    'message'     => StringUtils::asString($row['message'] ?? ''),
                    'link'        => is_string($row['link'] ?? null) ? $row['link'] : null,
                    'published'   => self::date($row['published_at'] ?? null, 'Y-m-d') ?? '',
                    'deprecation' => self::date($row['deprecation_at'] ?? null, \DateTimeInterface::ATOM),
                    'sunset'      => self::date($row['sunset_at'] ?? null, \DateTimeInterface::ATOM),
                ];
            }
        }
        return $out;
    }

    /**
     * The headers for these notices: the earliest deprecation (RFC 9745, `@<unix time>`) and sunset (RFC 8594,
     * an HTTP-date), each with a `Link` to its notice's page when it has one.
     *
     * @param list<Notice> $notices
     * @return array<string, list<string>>
     */
    public static function headers(array $notices): array
    {
        $headers = [];
        $links   = [];
        foreach (['deprecation' => 'Deprecation', 'sunset' => 'Sunset'] as $field => $header) {
            $earliest = null;
            foreach ($notices as $n) {
                if ($n[$field] !== null && ( $earliest === null || strtotime($n[$field]) < strtotime($earliest[$field] ?? '') )) {
                    $earliest = $n;
                }
            }
            if ($earliest === null || $earliest[$field] === null) {
                continue;
            }
            $time             = (int) strtotime($earliest[$field]);
            $headers[$header] = [$field === 'deprecation' ? '@' . $time : gmdate('D, d M Y H:i:s', $time) . ' GMT'];
            if ($earliest['link'] !== null) {
                $links[] = '<' . $earliest['link'] . '>; rel="' . $field . '"; type="text/html"';
            }
        }
        if ($links !== []) {
            $headers['Link'] = $links;
        }
        return $headers;
    }

    /** @param array<string, mixed> $row */
    private static function targets(array $row, ?Credential $credential, ?string $installationVersion): bool
    {
        $type = $row['target_credential_type'] ?? null;
        if (is_string($type) && $credential?->type->value !== $type) {
            return false;
        }
        $plugin = $row['target_plugin'] ?? null;
        if (!is_string($plugin)) {
            return true;
        }
        if ($credential?->type !== CredentialType::Installation || $credential->plugin !== $plugin) {
            return false;
        }
        $below = $row['target_below_version'] ?? null;
        return !is_string($below) || $installationVersion === null || version_compare($installationVersion, $below, '<');
    }

    private static function date(mixed $value, string $format): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = strtotime($value);
        return $time === false ? null : ( new \DateTimeImmutable('@' . $time) )->format($format);
    }
}
