<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

use Dotenv\Dotenv;

/**
 * Loads the .env* files without overriding the process environment.
 *
 * Dotenv's immutable repository only sees variables PHP mirrored into $_ENV /
 * $_SERVER. The CLI server (and any SAPI whose variables_order lacks "E") does
 * not mirror them, so a value in .env.local would silently beat one set in the
 * process environment. So the process environment is first copied into $_ENV
 * and $_SERVER (never replacing what is already there): Dotenv then leaves
 * those variables alone, and code reading $_ENV directly sees them too.
 */
final class EnvLoader
{
    public const FILES = ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'];

    public static function create(string $dir): Dotenv
    {
        foreach (getenv() as $name => $value) {
            $_ENV[$name]    ??= $value;
            $_SERVER[$name] ??= $value;
        }
        return Dotenv::createImmutable($dir, self::FILES, false);
    }
}
