<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Middleware;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Http\Exception\BearerUnauthorizedException;
use BibleGet\Api\Http\Exception\ForbiddenException;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Services\JwksProvider;
use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates a Zitadel access token sent as `Authorization: Bearer <jwt>` and
 * attaches the signed-in user to the request as the `oidc_user` attribute:
 * array{sub: string, email: string, email_verified: bool, roles: list<string>}.
 *
 * The token must be an RS256 JWT signed by a key of Zitadel's JWKS, with
 * `iss` equal to ZITADEL_ISSUER, `aud` containing ZITADEL_AUDIENCE (the
 * BibleGet project id), a `sub`, and `exp`/`nbf` valid within a 60 s leeway.
 * Roles are the keys of the `urn:zitadel:iam:org:project:roles` claim.
 *
 * There are no cookies and no /auth routes: the frontend runs the login flow
 * and calls these routes server-side with the access token. When
 * ZITADEL_ISSUER or ZITADEL_AUDIENCE is unset the routes answer 503 and no
 * token is ever accepted.
 */
class OidcBearerMiddleware implements MiddlewareInterface
{
    public const ROLES_CLAIM = 'urn:zitadel:iam:org:project:roles';
    public const LEEWAY      = 60;

    private const BEARER_PATTERN = '/^Bearer +([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*)\z/i';

    private readonly ?string $issuer;
    private readonly ?string $audience;
    private ?string $requiredRole = null;

    public function __construct(
        private readonly ?JwksProvider $jwks = null,
        ?string $issuer = null,
        ?string $audience = null
    ) {
        $this->issuer   = self::nonEmpty($issuer ?? CredentialFactory::env('ZITADEL_ISSUER'));
        $this->audience = self::nonEmpty($audience ?? CredentialFactory::env('ZITADEL_AUDIENCE'));
    }

    /** A copy of this middleware that also requires the given project role (403 without it); null requires none. */
    public function requireRole(?string $role): self
    {
        $clone               = clone $this;
        $clone->requiredRole = $role;
        return $clone;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }
        if ($this->issuer === null || $this->audience === null) {
            throw new ServiceUnavailableException('Sign-in is not configured on this server (ZITADEL_ISSUER, ZITADEL_AUDIENCE).');
        }

        if (preg_match(self::BEARER_PATTERN, trim($request->getHeaderLine('Authorization')), $m) !== 1) {
            throw new BearerUnauthorizedException();
        }
        $user = $this->validate($m[1], $this->issuer, $this->audience);

        if ($this->requiredRole !== null && !in_array($this->requiredRole, $user['roles'], true)) {
            throw new ForbiddenException('This route requires the ' . $this->requiredRole . ' role.');
        }
        return $handler->handle($request->withAttribute('oidc_user', $user));
    }

    /** @return array{sub: string, email: string, email_verified: bool, roles: list<string>} */
    private function validate(string $token, string $issuer, string $audience): array
    {
        $kid = self::rs256Kid($token);
        try {
            $keys = ( $this->jwks ?? new JwksProvider($issuer) )->keysFor($kid);
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailableException('The sign-in keys could not be loaded.', $e);
        }
        if (!isset($keys[$kid])) {
            throw new BearerUnauthorizedException('The access token was not signed by a known key.');
        }

        $leeway      = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        try {
            $claims = (array) JWT::decode($token, $keys[$kid]);
        } catch (\Throwable $e) {
            throw new BearerUnauthorizedException('The access token is invalid or expired.', $e);
        } finally {
            JWT::$leeway = $leeway;
        }

        if (!is_int($claims['exp'] ?? null)) {
            throw new BearerUnauthorizedException('The access token has no expiry.');
        }
        if (!is_string($claims['iss'] ?? null) || rtrim($claims['iss'], '/') !== rtrim($issuer, '/')) {
            throw new BearerUnauthorizedException('The access token was issued by another issuer.');
        }
        if (!in_array($audience, self::audiences($claims['aud'] ?? null), true)) {
            throw new BearerUnauthorizedException('The access token is not meant for this API.');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new BearerUnauthorizedException('The access token has no subject.');
        }

        return [
            'sub'            => $claims['sub'],
            'email'          => is_string($claims['email'] ?? null) ? $claims['email'] : '',
            'email_verified' => ( $claims['email_verified'] ?? null ) === true,
            'roles'          => self::roles($claims[self::ROLES_CLAIM] ?? null),
        ];
    }

    /** The kid of an RS256 token header; anything else (alg none, HS*, no kid) is a 401. */
    private static function rs256Kid(string $token): string
    {
        $raw    = base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true);
        $header = $raw === false ? null : json_decode($raw, true);
        if (!is_array($header) || ( $header['alg'] ?? null ) !== JwksProvider::ALGORITHM) {
            throw new BearerUnauthorizedException('The access token must be signed with ' . JwksProvider::ALGORITHM . '.');
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            throw new BearerUnauthorizedException('The access token header has no key id.');
        }
        return $kid;
    }

    /** @return list<string> */
    private static function audiences(mixed $aud): array
    {
        if (is_string($aud)) {
            return [$aud];
        }
        return is_array($aud) ? array_values(array_filter($aud, 'is_string')) : [];
    }

    /** @return list<string> the keys of Zitadel's project-roles claim */
    private static function roles(mixed $claim): array
    {
        if (!$claim instanceof \stdClass) {
            return [];
        }
        return array_values(array_filter(array_keys(get_object_vars($claim)), 'is_string'));
    }

    private static function nonEmpty(?string $v): ?string
    {
        return $v === null || trim($v) === '' ? null : trim($v);
    }
}
