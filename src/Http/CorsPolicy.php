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
 */
class CorsPolicy
{
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
        $origin = $request->getHeaderLine('Origin');

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
