<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Middleware;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Http\Exception\TooManyRequestsException;
use BibleGet\Api\Services\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Per-credential sliding one-hour window. Must run after CredentialMiddleware.
 *
 * Requests without a credential (only possible while REQUIRE_CREDENTIALS is
 * off) and requests with a system credential are not limited. Allowed
 * responses carry X-RateLimit-Limit/Remaining/Reset; an exceeded limit
 * throws TooManyRequestsException, which ErrorHandlingMiddleware turns into
 * a 429 with Retry-After.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public const DEFAULT_PROJECT_LIMIT      = 1000;
    public const DEFAULT_INSTALLATION_LIMIT = 300;

    private readonly RateLimiter $limiter;

    public function __construct(?RateLimiter $limiter = null)
    {
        $this->limiter = $limiter ?? new RateLimiter();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $credential = $request->getAttribute('credential');
        if (!$credential instanceof Credential || $credential->isSystem) {
            return $handler->handle($request);
        }

        $limit  = $credential->ratePerHour ?? self::defaultLimit($credential->type);
        $result = $this->limiter->hit($credential->type->value . ':' . $credential->id, $limit);
        if (!$result['allowed']) {
            throw new TooManyRequestsException(
                "Rate limit exceeded: at most {$limit} requests per hour for this credential.",
                max(1, $result['reset'] - time())
            );
        }

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result['remaining'])
            ->withHeader('X-RateLimit-Reset', (string) $result['reset']);
    }

    private static function defaultLimit(CredentialType $type): int
    {
        return $type === CredentialType::Installation ? self::DEFAULT_INSTALLATION_LIMIT : self::DEFAULT_PROJECT_LIMIT;
    }
}
