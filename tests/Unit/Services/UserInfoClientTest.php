<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Services\UserInfoClient;
use BibleGet\Tests\Support\IsolatesEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserInfoClientTest extends TestCase
{
    use IsolatesEnvironment;

    protected function setUp(): void
    {
        $this->isolateEnvironment(['APP_ENV', 'ZITADEL_USERINFO_URL']);
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();
    }

    public function testEndpointIsTheIssuersUserinfoPath(): void
    {
        self::assertSame('https://auth.example.org/oidc/v1/userinfo', ( new UserInfoClient('https://auth.example.org/') )->endpoint());
    }

    /** @return array<string, array{string}> */
    public static function acceptedOverrides(): array
    {
        return [
            'loopback IPv4' => ['http://127.0.0.1:9/userinfo'],
            'localhost'     => ['http://localhost:8095/oidc/v1/userinfo'],
            'loopback IPv6' => ['http://[::1]:9/userinfo'],
            'https'         => ['https://auth.staging.example.org/oidc/v1/userinfo'],
        ];
    }

    #[DataProvider('acceptedOverrides')]
    public function testUrlOverrideIsHonouredOutsideProduction(string $url): void
    {
        $_ENV['APP_ENV']              = 'development';
        $_ENV['ZITADEL_USERINFO_URL'] = $url;
        self::assertSame($url, ( new UserInfoClient('https://auth.example.org') )->endpoint());
    }

    /** @return array<string, array{string}> */
    public static function rejectedOverrides(): array
    {
        return [
            'plaintext remote host' => ['http://zitadel.lan/oidc/v1/userinfo'],
            'plaintext lookalike'   => ['http://localhost.example.org/userinfo'],
            'other scheme'          => ['ftp://127.0.0.1/userinfo'],
            'no host'               => ['http:///userinfo'],
        ];
    }

    /** The caller's bearer token must not travel in plaintext beyond this machine. */
    #[DataProvider('rejectedOverrides')]
    public function testPlaintextOverrideToAnotherHostIsRejected(string $url): void
    {
        $_ENV['APP_ENV']              = 'development';
        $_ENV['ZITADEL_USERINFO_URL'] = $url;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ZITADEL_USERINFO_URL');
        new UserInfoClient('https://auth.example.org');
    }

    public function testUrlOverrideIsIgnoredInProduction(): void
    {
        $_ENV['APP_ENV']              = 'production';
        $_ENV['ZITADEL_USERINFO_URL'] = 'http://127.0.0.1:9/userinfo';
        self::assertSame('https://auth.example.org/oidc/v1/userinfo', ( new UserInfoClient('https://auth.example.org') )->endpoint());
    }
}
