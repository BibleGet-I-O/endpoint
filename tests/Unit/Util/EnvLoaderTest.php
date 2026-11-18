<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Util\EnvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Process environment variables win over the .env* files, even when PHP does
 * not mirror the environment into $_ENV / $_SERVER (the CLI server's default
 * variables_order), as the test servers and the mint scripts rely on.
 */
final class EnvLoaderTest extends TestCase
{
    private const KEY = 'BIBLEGET_ENVLOADER_TEST';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bibleget-envloader-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/.env.local', self::KEY . "=from-file\n");
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        putenv(self::KEY);
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        putenv(self::KEY);
        @unlink($this->dir . '/.env.local');
        @rmdir($this->dir);
    }

    public function testProcessEnvironmentOnlyVisibleToGetenvWinsOverFiles(): void
    {
        putenv(self::KEY . '=from-process');
        EnvLoader::create($this->dir)->safeLoad();
        self::assertSame('from-process', CredentialFactory::env(self::KEY));
        // Code that reads $_ENV directly (Router's API_BASE_PATH) must see it too.
        self::assertSame('from-process', $_ENV[self::KEY] ?? null);
    }

    public function testFileValueIsUsedWhenTheProcessDoesNotSetIt(): void
    {
        EnvLoader::create($this->dir)->safeLoad();
        self::assertSame('from-file', CredentialFactory::env(self::KEY));
    }
}
