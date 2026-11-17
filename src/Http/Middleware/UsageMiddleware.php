<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Middleware;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Logs\LoggerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Counts each data-route request in usage_daily once the handler has
 * returned: one row per day, credential and route.
 *
 * Requests without a credential are counted under credential_type 'none'
 * with the nil UUID. Best-effort: a failure is logged and the response is
 * returned unchanged. CORS preflights are not counted.
 */
class UsageMiddleware implements MiddlewareInterface
{
    public const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * @param string $route usage route name, e.g. `quote`, `metadata`, `search/keyword`
     */
    public function __construct(private readonly string $route, private readonly ?\PDO $db = null)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if ($request->getMethod() !== 'OPTIONS') {
            $this->record($request->getAttribute('credential'));
        }
        return $response;
    }

    private function record(mixed $credential): void
    {
        [$type, $id] = $credential instanceof Credential
            ? [$credential->type->value, $credential->id]
            : ['none', self::NIL_UUID];
        try {
            ( $this->db ?? Connection::getConnection() )->prepare(
                'INSERT INTO usage_daily (day, credential_type, credential_id, route, requests)
                 VALUES (current_date, :t, :i, :r, 1)
                 ON CONFLICT (day, credential_type, credential_id, route)
                 DO UPDATE SET requests = usage_daily.requests + 1'
            )->execute([':t' => $type, ':i' => $id, ':r' => $this->route]);
        } catch (\Throwable $e) {
            try {
                LoggerFactory::create('usage')->error('usage_daily upsert failed: ' . $e->getMessage(), [
                    'route' => $this->route,
                ]);
            } catch (\Throwable) {
                // nothing left to report to
            }
        }
    }
}
