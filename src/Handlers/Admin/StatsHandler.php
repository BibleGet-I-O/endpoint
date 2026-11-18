<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Admin;

use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Repositories\StatsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /admin/stats?from=&to= (role bibleget_admin):
 *
 *   installations           per plugin and version: total registered, active_30d
 *                           (active, last seen within 30 days) — a current snapshot
 *   registrations_per_hour  new installations per client key per UTC hour in the window
 *   requests                requests per day and route for the window's days,
 *                           summed per application, per plugin and version, or
 *                           for uncredentialed requests ('none'), with a label
 *
 * from and to are ISO-8601 dates or date-times (UTC when no offset); a date-only
 * `to` includes that whole day. Default: the 30 days up to now; at most 366 days.
 */
class StatsHandler extends AbstractAdminHandler
{
    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS     = 366;

    protected function dispatch(ServerRequestInterface $request, string $method, array $path, string $admin): ResponseInterface
    {
        if ($path !== []) {
            throw new NotFoundException();
        }
        self::requireMethod($method, ['GET']);

        [$from, $to] = self::window($request->getQueryParams());
        $stats       = new StatsRepository($this->db());
        return $this->json($request, [
            'from'                   => $from->format('Y-m-d\TH:i:s\Z'),
            'to'                     => $to->format('Y-m-d\TH:i:s\Z'),
            'installations'          => $stats->installations(),
            'registrations_per_hour' => $stats->registrationsPerHour($from, $to),
            'requests'               => $stats->requests($from->format('Y-m-d'), $to->modify('-1 microsecond')->format('Y-m-d')),
        ]);
    }

    /**
     * The [from, to) window, UTC.
     *
     * @param array<mixed> $q
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function window(array $q): array
    {
        $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (isset($q['to'])) {
            $to = self::isoDate($q['to'], 'to');
            if (self::isDateOnly(is_string($q['to']) ? $q['to'] : '')) {
                $to = $to->modify('+1 day');
            }
        }
        $from = isset($q['from'])
            ? self::isoDate($q['from'], 'from')
            : $to->modify('-' . self::DEFAULT_DAYS . ' days');
        if ($from >= $to) {
            throw new ValidationException('from must be earlier than to.');
        }
        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_DAYS * 86400) {
            throw new ValidationException('The window from–to may be at most ' . self::MAX_DAYS . ' days.');
        }
        return [$from, $to];
    }
}
