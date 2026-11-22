<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Admin;

use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Enum\StatusCode;
use BibleGet\Api\Http\Exception\ConflictException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Service notices (role bibleget_admin), carried in every data-route answer (src/Services/ServiceNotices.php):
 *
 *   GET    /admin/service-notices        every notice, current, scheduled or expired
 *   POST   /admin/service-notices        {id, message, link?, published?, expires?, deprecation?, sunset?,
 *                                         plugin?, below_version?, credential_type?} → the notice
 *   DELETE /admin/service-notices/{id}   withdraws it (kept, with withdrawn_at, so its id is not used again)
 *
 * A notice is not edited: its id is what a client remembers having shown, so a changed notice is a new one.
 */
class ServiceNoticeAdminHandler extends AbstractAdminHandler
{
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{2,63}\z/';
    private const FIELDS     = ['id', 'message', 'link', 'published', 'expires', 'deprecation', 'sunset', 'plugin', 'below_version', 'credential_type'];
    private const DATES      = ['published' => 'published_at', 'expires' => 'expires_at', 'deprecation' => 'deprecation_at', 'sunset' => 'sunset_at'];

    /** @param string[] $requestPathParams */
    public function __construct(ResponseFactoryInterface $responseFactory, array $requestPathParams = [])
    {
        parent::__construct($responseFactory, $requestPathParams);
        // Withdrawing a notice is a DELETE (the other admin routes need GET, POST and PATCH only).
        $this->allowedRequestMethods[] = RequestMethod::DELETE;
    }

    protected function dispatch(ServerRequestInterface $request, string $method, array $path, string $admin): ResponseInterface
    {
        if ($path === []) {
            self::requireMethod($method, ['GET', 'POST']);
            return $method === 'GET' ? $this->listNotices($request) : $this->publish($request);
        }
        if (count($path) !== 1 || preg_match(self::ID_PATTERN, $path[0]) !== 1) {
            throw new NotFoundException();
        }
        self::requireMethod($method, ['DELETE']);
        $stmt = $this->db()->prepare('UPDATE service_notices SET withdrawn_at = now() WHERE id = ? AND withdrawn_at IS NULL');
        $stmt->execute([$path[0]]);
        if ($stmt->rowCount() === 0) {
            throw new NotFoundException('Service notice not found.');
        }
        return $this->noContent($request);
    }

    private function listNotices(ServerRequestInterface $request): ResponseInterface
    {
        $stmt    = $this->db()->query('SELECT * FROM service_notices ORDER BY published_at DESC, id');
        $notices = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $this->json($request, ['service_notices' => $notices, 'total' => count($notices)]);
    }

    private function publish(ServerRequestInterface $request): ResponseInterface
    {
        $row  = self::validated($this->getJsonBodyParams($request));
        $cols = array_keys($row);
        $stmt = $this->db()->prepare(
            'INSERT INTO service_notices (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')
             ON CONFLICT (id) DO NOTHING RETURNING *'
        );
        $stmt->execute(array_values($row));
        $created = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($created)) {
            throw new ConflictException('A service notice with this id exists or was withdrawn: a new or changed notice needs a new id.');
        }
        return $this->json($request, ['service_notice' => $created], StatusCode::CREATED);
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, string>
     * @throws ValidationException
     */
    private static function validated(array $p): array
    {
        $unknown = array_diff(array_keys($p), self::FIELDS);
        if ($unknown !== []) {
            throw new ValidationException('Unknown field(s): ' . implode(', ', $unknown) . '. A notice has ' . implode(', ', self::FIELDS) . '.');
        }
        $id      = $p['id'] ?? null;
        $message = $p['message'] ?? null;
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new ValidationException('id is required: 3 to 64 lowercase letters, digits or hyphens, starting with a letter or digit.');
        }
        if (!is_string($message) || trim($message) === '' || mb_strlen($message) > 1000 || !self::isCleanMultilineText($message)) {
            throw new ValidationException('message is required: 1 to 1000 characters of text.');
        }
        $row  = ['id' => $id, 'message' => trim($message)];
        $link = $p['link'] ?? null;
        if ($link !== null) {
            if (!is_string($link) || !str_starts_with($link, 'https://') || strlen($link) > 500 || filter_var($link, FILTER_VALIDATE_URL) === false) {
                throw new ValidationException('link must be an https URL of at most 500 characters.');
            }
            $row['link'] = $link;
        }
        $at = [];
        foreach (self::DATES as $field => $column) {
            if (( $p[$field] ?? null ) === null) {
                continue;
            }
            $instant = self::instant($p[$field]);
            if ($instant === null) {
                throw new ValidationException($field . ' must be an ISO 8601 date and time with its offset, e.g. 2027-06-30T00:00:00Z.');
            }
            $at[$column] = $instant;
        }
        // Without published, now: set here, so the instant checked against expires is the one stored.
        $at['published_at'] ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (isset($at['expires_at']) && $at['expires_at'] <= $at['published_at']) {
            throw new ValidationException('expires must be later than published (or than now, when published is not given).');
        }
        foreach ($at as $column => $instant) {
            // Microseconds kept: 00:00:00.1 and 00:00:00.9 are two instants.
            $row[$column] = $instant->format('Y-m-d\TH:i:s.u\Z');
        }
        return $row + self::target($p);
    }

    /**
     * An explicit ISO 8601 instant (2027-06-30T00:00:00Z, 2027-06-30T02:00:00+02:00, seconds and fractions optional)
     * in UTC, to the microsecond; null for anything else: a relative expression ("next Thursday"), no offset, an
     * impossible date.
     */
    private static function instant(mixed $v): ?\DateTimeImmutable
    {
        $re = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})\z/';
        if (!is_string($v) || preg_match($re, $v, $m) !== 1) {
            return null;
        }
        [$year, $month, $day, $hour, $minute] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]];
        $second                               = $m[6] === '' ? 0 : (int) $m[6];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        try {
            return ( new \DateTimeImmutable($v) )->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, string>
     * @throws ValidationException
     */
    private static function target(array $p): array
    {
        $row    = [];
        $plugin = $p['plugin'] ?? null;
        $below  = $p['below_version'] ?? null;
        $type   = $p['credential_type'] ?? null;
        if ($plugin !== null) {
            if (!is_string($plugin) || !in_array($plugin, ClientKeyAdminHandler::PLUGINS, true)) {
                throw new ValidationException('plugin must be one of ' . implode(', ', ClientKeyAdminHandler::PLUGINS) . '.');
            }
            $row['target_plugin'] = $plugin;
        }
        if ($below !== null) {
            if ($plugin === null || !is_string($below) || preg_match(ClientKeyAdminHandler::VERSION_PATTERN, $below) !== 1) {
                throw new ValidationException('below_version needs a plugin, and 1 to 50 letters, digits or . + _ -');
            }
            $row['target_below_version'] = $below;
        }
        if ($type !== null) {
            if (!in_array($type, ['project', 'installation'], true)) {
                throw new ValidationException('credential_type must be project or installation.');
            }
            $row['target_credential_type'] = $type;
        }
        return $row;
    }
}
