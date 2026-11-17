<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Middleware;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\ForbiddenException;
use BibleGet\Api\Http\Exception\UnauthorizedException;
use BibleGet\Api\Repositories\ApiKeyRepository;
use BibleGet\Api\Repositories\ClientKeyRepository;
use BibleGet\Api\Repositories\InstallationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the X-Api-Key header to a Credential and attaches it to the
 * request as the `credential` attribute (null when no key was sent and
 * credentials are not required).
 *
 * The key is read from the X-Api-Key header only: a key in the query string
 * or the Authorization header is ignored. CORS preflight requests pass
 * through untouched, since browsers never send custom headers on them.
 * In production a `_test_` credential is refused (CredentialFactory::typeOf).
 */
class CredentialMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-Api-Key';

    public function __construct(private readonly ?\PDO $db = null)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request->withAttribute('credential', null));
        }

        $presented = trim($request->getHeaderLine(self::HEADER));
        if ($presented === '') {
            if (CredentialFactory::credentialsRequired()) {
                throw new UnauthorizedException(
                    'A credential is required: send your project key in the X-Api-Key header.'
                );
            }
            return $handler->handle($request->withAttribute('credential', null));
        }

        return $handler->handle($request->withAttribute('credential', $this->resolve($presented)));
    }

    private function resolve(string $presented): Credential
    {
        $type = CredentialFactory::typeOf($presented);
        if ($type === null) {
            throw self::invalid();
        }
        $hash = CredentialFactory::hash($presented);
        $db   = $this->db ?? Connection::getConnection();

        if ($type === CredentialType::Client) {
            if (( new ClientKeyRepository($db) )->findActiveByHash($hash) !== null) {
                throw new ForbiddenException('Client keys can only register installations; send the installation token in the X-Api-Key header.');
            }
            throw self::invalid();
        }

        if ($type === CredentialType::Project) {
            $repo       = new ApiKeyRepository($db);
            $credential = $repo->findActiveByHash($hash);
            if ($credential === null) {
                throw self::invalid();
            }
            $repo->touch($credential->id);
            return $credential;
        }

        $repo       = new InstallationRepository($db);
        $credential = $repo->findActiveByHash($hash);
        if ($credential === null) {
            throw self::invalid();
        }
        $repo->touch($credential->id);
        return $credential;
    }

    private static function invalid(): UnauthorizedException
    {
        return new UnauthorizedException(
            'The credential in the X-Api-Key header is unknown, revoked, expired or not yet approved.'
        );
    }
}
