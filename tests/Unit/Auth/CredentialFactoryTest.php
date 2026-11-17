<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Auth;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Auth\CredentialType;
use PHPUnit\Framework\TestCase;

class CredentialFactoryTest extends TestCase
{
    public function testFormatPerTypeAndEnv(): void
    {
        self::assertMatchesRegularExpression(
            '/^bibleget_live_[0-9a-f]{32}$/',
            CredentialFactory::generate(CredentialType::Project, 'production')['plain']
        );
        self::assertMatchesRegularExpression(
            '/^bgclient_test_[0-9a-f]{32}$/',
            CredentialFactory::generate(CredentialType::Client, 'staging')['plain']
        );
        self::assertMatchesRegularExpression(
            '/^bginst_test_[0-9a-f]{32}$/',
            CredentialFactory::generate(CredentialType::Installation, 'development')['plain']
        );
    }

    public function testHashIsSha256OfPlainAndPrefixIs20Chars(): void
    {
        $c = CredentialFactory::generate(CredentialType::Project, 'production');
        self::assertSame(hash('sha256', $c['plain']), $c['hash']);
        self::assertSame(substr($c['plain'], 0, 20), $c['prefix']);
        self::assertSame(20, strlen($c['prefix']));
        self::assertSame($c['hash'], CredentialFactory::hash($c['plain']));
    }

    public function testTypeOfRecognisesOnlyKnownPrefixes(): void
    {
        self::assertSame(CredentialType::Project, CredentialFactory::typeOf('bibleget_live_' . str_repeat('a', 32)));
        self::assertSame(CredentialType::Client, CredentialFactory::typeOf('bgclient_test_abc'));
        self::assertSame(CredentialType::Installation, CredentialFactory::typeOf('bginst_live_abc'));
        self::assertNull(CredentialFactory::typeOf('litcal_live_' . str_repeat('a', 32)));
        self::assertNull(CredentialFactory::typeOf(''));
        self::assertNull(CredentialFactory::typeOf('bibleget_x'));
    }

    public function testCurrentEnvReadsDotenvStyleSuperglobals(): void
    {
        $had = array_key_exists('APP_ENV', $_ENV);
        $old = $_ENV['APP_ENV'] ?? null;
        try {
            $_ENV['APP_ENV'] = 'production';
            self::assertSame('production', CredentialFactory::currentEnv());
            self::assertStringContainsString(
                '_live_',
                CredentialFactory::generate(CredentialType::Project, CredentialFactory::currentEnv())['plain']
            );
        } finally {
            if ($had) {
                $_ENV['APP_ENV'] = $old;
            } else {
                unset($_ENV['APP_ENV']);
            }
        }
    }
}
