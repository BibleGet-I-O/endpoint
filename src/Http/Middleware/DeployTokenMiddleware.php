<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates the deploy workflow's requests to /_ops/* with a long random shared token (DEPLOY_TOKEN, in
 * the server's .env.staging / .env.production and the GitHub environment's secrets), sent as X-Deploy-Token.
 * Fail-closed: APP_ENV outside {staging, production} or an empty DEPLOY_TOKEN answers 503, a missing or wrong
 * header 401. hash_equals keeps the comparison constant-time. Ported from the Liturgical Calendar API.
 */
final class DeployTokenMiddleware implements MiddlewareInterface
{
    public const HEADER           = 'X-Deploy-Token';
    private const ALLOWED_APP_ENV = ['staging', 'production'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(self::env('APP_ENV'), self::ALLOWED_APP_ENV, true)) {
            return self::text(503, "Deploy endpoint disabled in this environment\n");
        }
        $expected = self::env('DEPLOY_TOKEN');
        if ($expected === '') {
            return self::text(503, "Deploy endpoint not configured\n");
        }
        $provided = $request->getHeaderLine(self::HEADER);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return self::text(401, "Unauthorized\n");
        }
        return $handler->handle($request);
    }

    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return is_string($value) ? $value : '';
    }

    private static function text(int $status, string $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }
}
