<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Admin;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Handlers\AbstractHandler;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Enum\StatusCode;
use BibleGet\Api\Http\Exception\BearerUnauthorizedException;
use BibleGet\Api\Http\Exception\MethodNotAllowedException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Base of the /admin/* handlers. The Router puts them behind
 * OidcBearerMiddleware::requireRole('bibleget_admin'), which sets the
 * `oidc_user` attribute; the admin's `sub` is recorded as the reviewer.
 *
 * Path segments are those after /admin (the first is the resource name).
 * Ids in the path must be UUIDs (else 404, before any query); bodies are read
 * from the JSON body only; every response is Cache-Control: no-store.
 */
abstract class AbstractAdminHandler extends AbstractHandler
{
    public const RATE_LIMIT_MAX = 1_000_000;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';
    private const ISO_PATTERN  = '/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?\z/';

    protected ?\PDO $db = null;

    /** @param string[] $requestPathParams */
    public function __construct(ResponseFactoryInterface $responseFactory, array $requestPathParams = [])
    {
        parent::__construct($responseFactory, $requestPathParams);
        $this->allowedRequestMethods      = [
            RequestMethod::GET,
            RequestMethod::POST,
            RequestMethod::PATCH,
            RequestMethod::OPTIONS,
        ];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
    }

    /**
     * Serves the route named by the path segments after the resource name.
     *
     * @param list<string> $path
     */
    abstract protected function dispatch(ServerRequestInterface $request, string $method, array $path, string $admin): ResponseInterface;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response(200, [], null, $request->getProtocolVersion(), 'OK');
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);
        $this->validateRequestContentType($request);

        $user = $request->getAttribute('oidc_user');
        if (!is_array($user) || !is_string($user['sub'] ?? null)) {
            throw new BearerUnauthorizedException();
        }
        return $this->dispatch($request, $request->getMethod(), array_values(array_slice($this->requestPathParams, 1)), $user['sub']);
    }

    /** @param list<string> $allowed */
    protected static function requireMethod(string $method, array $allowed): void
    {
        if (!in_array($method, $allowed, true)) {
            throw new MethodNotAllowedException();
        }
    }

    /** A path id, lower-cased; anything but a UUID is a 404. */
    protected static function pathId(string $v): string
    {
        if (!self::isUuid($v)) {
            throw new NotFoundException();
        }
        return strtolower($v);
    }

    protected static function isUuid(string $v): bool
    {
        return preg_match(self::UUID_PATTERN, $v) === 1;
    }

    /** rate_limit_per_hour from the body: a JSON integer from 1 to 1,000,000. */
    protected function rateLimitFromBody(ServerRequestInterface $request): int
    {
        $v = $this->getJsonBodyParams($request)['rate_limit_per_hour'] ?? null;
        if (!is_int($v) || $v < 1 || $v > self::RATE_LIMIT_MAX) {
            throw new ValidationException('rate_limit_per_hour must be an integer from 1 to 1000000.');
        }
        return $v;
    }

    /**
     * An ISO-8601 date (YYYY-MM-DD) or date-time (with optional seconds,
     * fraction and Z or ±hh:mm offset; UTC when none), as UTC.
     */
    protected static function isoDate(mixed $v, string $field): \DateTimeImmutable
    {
        $invalid = new ValidationException("{$field} must be an ISO-8601 date or date-time, such as 2026-11-17T09:00:00Z.");
        if (!is_string($v) || preg_match(self::ISO_PATTERN, $v) !== 1) {
            throw $invalid;
        }
        try {
            $d = new \DateTimeImmutable($v, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw $invalid;
        }
        // Reject values PHP would roll over (2026-02-31, 25:00) rather than parse literally.
        $shown = strlen($v) === 10 ? $d->format('Y-m-d') : $d->format('Y-m-d\TH:i');
        if ($shown !== substr($v, 0, strlen($shown))) {
            throw $invalid;
        }
        return $d->setTimezone(new \DateTimeZone('UTC'));
    }

    protected static function isDateOnly(string $v): bool
    {
        return strlen($v) === 10;
    }

    protected function json(ServerRequestInterface $request, mixed $data, StatusCode $status = StatusCode::OK): ResponseInterface
    {
        $response = $this->initResponse($request, 'application/json')
            ->withStatus($status->value, $status->reason())
            ->withHeader('Cache-Control', 'no-store');
        return $this->jsonResponse($response, $data);
    }

    protected function noContent(ServerRequestInterface $request): ResponseInterface
    {
        return $this->initResponse($request, 'application/json')
            ->withStatus(StatusCode::NO_CONTENT->value, StatusCode::NO_CONTENT->reason())
            ->withHeader('Cache-Control', 'no-store')
            ->withoutHeader('Content-Type');
    }

    protected function db(): \PDO
    {
        return $this->db ??= Connection::getConnection();
    }
}
