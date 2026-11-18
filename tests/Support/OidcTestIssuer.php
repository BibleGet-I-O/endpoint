<?php

declare(strict_types=1);

namespace BibleGet\Tests\Support;

use Firebase\JWT\JWT;

/**
 * A stand-in Zitadel for tests: an RSA key pair generated at runtime (no
 * private key is ever committed), a JWKS file holding its public half, and
 * signed access tokens.
 */
final class OidcTestIssuer
{
    public const ISSUER   = 'https://zitadel.test.invalid';
    public const AUDIENCE = 'bibleget-test-project';
    public const KID      = 'test-key-1';
    public const ROLES    = 'urn:zitadel:iam:org:project:roles';

    public readonly string $privateKey;
    /** @var array{n: string, e: string} */
    private array $publicParts;

    public function __construct(public readonly string $kid = self::KID)
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false || !openssl_pkey_export($res, $pem)) {
            throw new \RuntimeException('Could not generate a test RSA key pair.');
        }
        $details = openssl_pkey_get_details($res);
        if ($details === false || !is_string($pem)) {
            throw new \RuntimeException('Could not read the test RSA key.');
        }
        $this->privateKey  = $pem;
        $this->publicParts = ['n' => self::b64url($details['rsa']['n']), 'e' => self::b64url($details['rsa']['e'])];
    }

    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => $this->kid,
                    'n'   => $this->publicParts['n'],
                    'e'   => $this->publicParts['e'],
                ]
            ]
        ];
    }

    /** Writes the JWKS to a new temp file and returns its path. */
    public function writeJwksFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bibleget-jwks-');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temp JWKS file.');
        }
        file_put_contents($path, json_encode($this->jwks(), JSON_THROW_ON_ERROR));
        return $path;
    }

    /**
     * A signed RS256 access token; $overrides replace (or, set to null, remove) default claims.
     *
     * @param array<string, mixed> $overrides
     * @param list<string>         $roles
     */
    public function token(string $sub, array $overrides = [], array $roles = []): string
    {
        $now    = time();
        $claims = [
            'iss'            => self::ISSUER,
            'aud'            => [self::AUDIENCE, 'some-client-id'],
            'sub'            => $sub,
            'iat'            => $now,
            'nbf'            => $now,
            'exp'            => $now + 600,
            'email'          => $sub . '@example.test',
            'email_verified' => true,
        ];
        if ($roles !== []) {
            $claims[self::ROLES] = array_fill_keys($roles, ['org-1' => 'example.test']);
        }
        foreach ($overrides as $k => $v) {
            if ($v === null) {
                unset($claims[$k]);
            } else {
                $claims[$k] = $v;
            }
        }
        return JWT::encode($claims, $this->privateKey, 'RS256', $this->kid);
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
