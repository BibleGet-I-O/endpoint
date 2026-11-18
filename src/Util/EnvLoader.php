<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;

/**
 * Loads the .env* files without overriding the process environment.
 *
 * Dotenv's default immutable repository only sees variables PHP mirrored into
 * $_ENV / $_SERVER. The CLI server (and any SAPI whose variables_order lacks
 * "E") does not mirror them, so a value in .env.local would silently beat one
 * set in the process environment. Reading getenv() too keeps the process
 * environment authoritative; values are still written to $_ENV / $_SERVER
 * only, never putenv().
 */
final class EnvLoader
{
    public const FILES = ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'];

    public static function create(string $dir): Dotenv
    {
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addReader(PutenvAdapter::class)
            ->addAdapter(EnvConstAdapter::class)
            ->addAdapter(ServerConstAdapter::class)
            ->immutable()
            ->make();
        return Dotenv::create($repository, $dir, self::FILES, false);
    }
}
