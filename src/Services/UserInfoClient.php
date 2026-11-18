<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Http\Exception\BearerUnauthorizedException;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Http\Logs\LoggerFactory;

/**
 * Asks Zitadel's userinfo endpoint whether the caller's email is verified,
 * presenting the caller's own access token. Zitadel (v4) puts `email` and
 * `email_verified` in the ID token and here, never in access tokens, and only
 * POST /applications needs them, so one call per project creation.
 *
 * ZITADEL_USERINFO_URL replaces {ZITADEL_ISSUER}/oidc/v1/userinfo for tests;
 * it is ignored when APP_ENV=production, where the token may only go to the
 * issuer.
 */
final class UserInfoClient
{
    private const TIMEOUT = 5;

    private readonly string $endpoint;

    public function __construct(string $issuer)
    {
        $override       = CredentialFactory::currentEnv() === 'production' ? null : CredentialFactory::env('ZITADEL_USERINFO_URL');
        $this->endpoint = $override !== null && trim($override) !== ''
            ? trim($override)
            : rtrim($issuer, '/') . '/oidc/v1/userinfo';
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * True when userinfo reports email_verified=true for $sub.
     *
     * @throws BearerUnauthorizedException when Zitadel rejects the token or answers for another user
     * @throws ServiceUnavailableException when Zitadel cannot be reached or answers unusably
     */
    public function emailVerified(string $token, string $sub): bool
    {
        $ctx     = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
                'timeout'         => self::TIMEOUT,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ]
        ]);
        $body    = @file_get_contents($this->endpoint, false, $ctx);
        $headers = http_get_last_response_headers() ?? [];
        $status  = preg_match('#^HTTP/\S+ (\d{3})#', $headers[0] ?? '', $m) === 1 ? (int) $m[1] : 0;

        if ($status === 401) {
            throw new BearerUnauthorizedException();
        }
        $claims = $status === 200 && is_string($body) ? json_decode($body, true) : null;
        if (!is_array($claims)) {
            LoggerFactory::create('api')->error('Zitadel userinfo did not answer usably', ['status' => $status]);
            throw new ServiceUnavailableException('Could not confirm your email address with the sign-in service; try again shortly.');
        }
        if (( $claims['sub'] ?? null ) !== $sub) {
            throw new BearerUnauthorizedException();
        }
        return ( $claims['email_verified'] ?? null ) === true;
    }
}
