<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

/**
 * The version the API reports in the X-BibleGet-Version header and in
 * info.ENDPOINT_VERSION.
 *
 * The deploy workflow writes a VERSION file at the project root with the
 * release tag name (production) or the full commit SHA (staging). When the file is absent (local
 * development) or its content is not a plain version string, the version is
 * "dev"; git is never run at request time.
 */
final class AppVersion
{
    public const FALLBACK    = 'dev';
    private const MAX_LENGTH = 64;

    private static ?string $cached = null;

    public static function current(): string
    {
        return self::$cached ??= self::read(dirname(__DIR__, 2));
    }

    /** Read and validate the VERSION file under $root, without caching. */
    public static function read(string $root): string
    {
        $path = rtrim($root, '/') . '/VERSION';
        if (!is_file($path)) {
            return self::FALLBACK;
        }
        $raw = @file_get_contents($path, false, null, 0, self::MAX_LENGTH + 1024);
        if ($raw === false) {
            return self::FALLBACK;
        }
        $version = trim($raw);
        if ($version === '' || strlen($version) > self::MAX_LENGTH || preg_match('/\A[A-Za-z0-9._+-]+\z/', $version) !== 1) {
            return self::FALLBACK;
        }
        return $version;
    }

    /** Drop the cached value (tests). */
    public static function reset(): void
    {
        self::$cached = null;
    }
}
