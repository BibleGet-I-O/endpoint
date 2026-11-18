<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Services\UserInfoClient;
use PHPUnit\Framework\TestCase;

final class UserInfoClientTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'ZITADEL_USERINFO_URL'] as $k) {
            $this->saved[$k] = getenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($v === false ? $k : "{$k}={$v}");
        }
    }

    public function testEndpointIsTheIssuersUserinfoPath(): void
    {
        self::assertSame('https://auth.example.org/oidc/v1/userinfo', ( new UserInfoClient('https://auth.example.org/') )->endpoint());
    }

    public function testUrlOverrideIsHonouredOutsideProduction(): void
    {
        $_ENV['APP_ENV']              = 'development';
        $_ENV['ZITADEL_USERINFO_URL'] = 'http://127.0.0.1:9/userinfo';
        self::assertSame('http://127.0.0.1:9/userinfo', ( new UserInfoClient('https://auth.example.org') )->endpoint());
    }

    public function testUrlOverrideIsIgnoredInProduction(): void
    {
        $_ENV['APP_ENV']              = 'production';
        $_ENV['ZITADEL_USERINFO_URL'] = 'http://127.0.0.1:9/userinfo';
        self::assertSame('https://auth.example.org/oidc/v1/userinfo', ( new UserInfoClient('https://auth.example.org') )->endpoint());
    }
}
