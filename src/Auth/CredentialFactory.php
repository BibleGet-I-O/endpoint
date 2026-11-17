<?php

declare(strict_types=1);

namespace BibleGet\Api\Auth;

final class CredentialFactory
{
    /**
     * @return array{plain: string, hash: string, prefix: string}
     */
    public static function generate(CredentialType $type, string $appEnv): array
    {
        $env   = $appEnv === 'production' ? 'live' : 'test';
        $plain = $type->prefix() . $env . '_' . bin2hex(random_bytes(16));
        return ['plain' => $plain, 'hash' => self::hash($plain), 'prefix' => substr($plain, 0, 20)];
    }

    public static function typeOf(string $presented): ?CredentialType
    {
        foreach (CredentialType::cases() as $type) {
            if (str_starts_with($presented, $type->prefix() . 'live_') || str_starts_with($presented, $type->prefix() . 'test_')) {
                return $type;
            }
        }
        return null;
    }

    public static function hash(string $presented): string
    {
        return hash('sha256', $presented);
    }

    public static function currentEnv(): string
    {
        return self::env('APP_ENV') ?? '';
    }

    /**
     * Reads a variable from $_ENV, then $_SERVER, then getenv(): phpdotenv
     * fills the first two, a process environment only the last.
     */
    public static function env(string $key): ?string
    {
        foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $v) {
            if (is_string($v)) {
                return $v;
            }
        }
        return null;
    }

    /** True in production whatever REQUIRE_CREDENTIALS says; elsewhere the variable, default false. */
    public static function credentialsRequired(): bool
    {
        if (self::currentEnv() === 'production') {
            return true;
        }
        return filter_var(self::env('REQUIRE_CREDENTIALS') ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }
}
