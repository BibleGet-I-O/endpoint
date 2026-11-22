<?php

declare(strict_types=1);

namespace BibleGet\Api\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Applies CORS Access-Control-Allow-Origin headers based on a configurable
 * origin allowlist (CORS_ALLOWED_ORIGINS environment variable).
 *
 * When CORS_ALLOWED_ORIGINS is set, only listed origins receive
 * Access-Control-Allow-Credentials: true. Unknown origins get the
 * Access-Control-Allow-Origin header without credentials support,
 * preventing arbitrary sites from making credentialed cross-origin requests.
 *
 * When CORS_ALLOWED_ORIGINS is unset or empty, any origin is treated as
 * allowed (preserving the previous open behaviour).
 *
 * Every response exposes the rate-limit headers (X-RateLimit-*, Retry-After) and the
 * API version (X-BibleGet-Version)
 * to browser scripts, which otherwise only see the CORS-safelisted ones.
 */
class CorsPolicy
{
    public const EXPOSED_HEADERS = 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, X-BibleGet-Version, Deprecation, Sunset, Link';

    /** @var string[] */
    private array $allowedOrigins;

    public function __construct()
    {
        $raw                  = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
        $this->allowedOrigins = is_string($raw) && $raw !== ''
            ? array_map('trim', explode(',', $raw))
            : [];
    }

    public function applyHeaders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $origin   = $request->getHeaderLine('Origin');
        $response = $response->withHeader('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);

        if ($origin === '') {
            return $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        if ($this->allowedOrigins === [] || in_array($origin, $this->allowedOrigins, true)) {
            return $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withAddedHeader('Vary', 'Origin');
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withAddedHeader('Vary', 'Origin');
    }
}
