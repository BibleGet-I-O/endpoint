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
 * it must be https, or http to a loopback host, and it is ignored when
 * APP_ENV=production, where the token may only go to the issuer.
 */
final class UserInfoClient
{
    private const TIMEOUT = 5;

    private readonly string $endpoint;

    public function __construct(string $issuer)
    {
        $override       = CredentialFactory::currentEnv() === 'production' ? null : CredentialFactory::env('ZITADEL_USERINFO_URL');
        $this->endpoint = $override !== null && trim($override) !== ''
            ? self::safeOverride(trim($override))
            : rtrim($issuer, '/') . '/oidc/v1/userinfo';
    }

    /**
     * The override receives the caller's bearer token, so it must be https, or
     * plain http only to this machine (localhost, 127.0.0.1, ::1).
     */
    private static function safeOverride(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host !== '' && ( $scheme === 'https' || ( $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true) ) )) {
            return $url;
        }
        throw new \RuntimeException('ZITADEL_USERINFO_URL must be an https URL, or http to localhost, 127.0.0.1 or ::1.');
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
