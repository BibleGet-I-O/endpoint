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
 * a 429 with Retry-After and the same X-RateLimit-* headers.
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

        $headers  = $this->enforce($credential);
        $response = $handler->handle($request);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    /**
     * Counts one request against the credential's hourly limit (system
     * credentials are not limited); also used by PATCH /installations/self,
     * which is outside this middleware.
     *
     * @return array<string, string> the X-RateLimit-* headers for the response
     * @throws TooManyRequestsException when the limit is exceeded
     */
    public function enforce(Credential $credential): array
    {
        if ($credential->isSystem) {
            return [];
        }
        $limit  = $credential->ratePerHour ?? self::defaultLimit($credential->type);
        $result = $this->limiter->hit($credential->type->value . ':' . $credential->id, $limit);
        if (!$result['allowed']) {
            throw new TooManyRequestsException(
                "Rate limit exceeded: at most {$limit} requests per hour for this credential.",
                max(1, $result['reset'] - time()),
                null,
                self::headers($limit, $result)
            );
        }
        return self::headers($limit, $result);
    }

    /**
     * X-RateLimit-* headers, sent on allowed responses and on the 429.
     *
     * @param array{allowed: bool, remaining: int, reset: int} $result
     * @return array<string, string>
     */
    public static function headers(int $limit, array $result): array
    {
        return [
            'X-RateLimit-Limit'     => (string) $limit,
            'X-RateLimit-Remaining' => (string) max(0, $result['remaining']),
            'X-RateLimit-Reset'     => (string) $result['reset'],
        ];
    }

    private static function defaultLimit(CredentialType $type): int
    {
        return $type === CredentialType::Installation ? self::DEFAULT_INSTALLATION_LIMIT : self::DEFAULT_PROJECT_LIMIT;
    }
}
