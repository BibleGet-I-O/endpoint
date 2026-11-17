<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Services\JwksProvider;
use BibleGet\Tests\Support\OidcTestIssuer;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Fetch failures fall back to the cached keys and are throttled like
 * refetches; ZITADEL_JWKS_FILE is never honoured in production.
 */
class JwksProviderTest extends TestCase
{
    private const START = 1_900_000_000;

    private static OidcTestIssuer $issuer;

    private string $cache;
    private int $now;
    private int $fetches;
    private bool $failing;
    private ?string $savedEnv;

    public static function setUpBeforeClass(): void
    {
        self::$issuer = new OidcTestIssuer('kid-a');
    }

    protected function setUp(): void
    {
        $this->cache    = sys_get_temp_dir() . '/bibleget-jwks-test-' . bin2hex(random_bytes(6)) . '.json';
        $this->now      = self::START;
        $this->fetches  = 0;
        $this->failing  = false;
        $this->savedEnv = $_ENV['APP_ENV'] ?? null;
        $this->resetWarnedFlag();
    }

    protected function tearDown(): void
    {
        @unlink($this->cache);
        if ($this->savedEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->savedEnv;
        }
        $this->resetWarnedFlag();
    }

    private function resetWarnedFlag(): void
    {
        $ref = new \ReflectionProperty(JwksProvider::class, 'jwksFileWarned');
        $ref->setValue(null, false);
    }

    private function provider(?string $jwksFile = null, ?Logger $logger = null): JwksProvider
    {
        $fetcher = function (string $url): string {
            $this->fetches++;
            if ($this->failing) {
                throw new \RuntimeException('Zitadel is down');
            }
            return (string) json_encode(self::$issuer->jwks());
        };
        $clock   = fn (): int => $this->now;
        return new JwksProvider(OidcTestIssuer::ISSUER, $this->cache, $jwksFile, 3600, $fetcher, $clock, $logger ?? new Logger('test', [new TestHandler()]));
    }

    public function testFailedFetchFallsBackToStaleCacheAndIsThrottled(): void
    {
        self::assertArrayHasKey('kid-a', $this->provider()->keysFor('kid-a'));
        self::assertSame(1, $this->fetches);

        // Past the TTL, Zitadel is down: the stale keys still verify tokens.
        $this->now    += 3601;
        $this->failing = true;
        $provider      = $this->provider();
        self::assertArrayHasKey('kid-a', $provider->keysFor('kid-a'));
        self::assertSame(2, $this->fetches);

        // The failed attempt is remembered, in memory and in the cache file:
        // no new attempt within a minute.
        $this->now += 10;
        self::assertArrayHasKey('kid-a', $provider->keysFor('kid-a'));
        self::assertArrayHasKey('kid-a', $this->provider()->keysFor('kid-a'));
        self::assertArrayHasKey('kid-a', $this->provider()->keysFor('unknown-kid'));
        self::assertSame(2, $this->fetches);

        // A minute after the attempt it is tried again, and success refreshes the cache.
        $this->now    += 51;
        $this->failing = false;
        self::assertArrayHasKey('kid-a', $this->provider()->keysFor('kid-a'));
        self::assertSame(3, $this->fetches);
        $this->now += 30;
        self::assertArrayHasKey('kid-a', $this->provider()->keysFor('kid-a'));
        self::assertSame(3, $this->fetches);
    }

    public function testFailedFetchWithoutCacheThrowsAndIsThrottled(): void
    {
        $this->failing = true;
        try {
            $this->provider()->keysFor('kid-a');
            self::fail('no keys and no fetch should throw');
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $this->fetches);

        $this->now += 30;
        try {
            $this->provider()->keysFor('kid-a');
            self::fail('no keys and no fetch should throw');
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $this->fetches, 'a second attempt within a minute is not made');

        $this->now    += 31;
        $this->failing = false;
        self::assertArrayHasKey('kid-a', $this->provider()->keysFor('kid-a'));
        self::assertSame(2, $this->fetches);
    }

    public function testJwksFileIsIgnoredInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $other           = new OidcTestIssuer('kid-from-file');
        $file            = $other->writeJwksFile();
        $records         = new TestHandler();
        $logger          = new Logger('test', [$records]);
        try {
            $keys = $this->provider($file, $logger)->keysFor('kid-a');
            self::assertArrayHasKey('kid-a', $keys);
            self::assertArrayNotHasKey('kid-from-file', $keys);
            self::assertSame(1, $this->fetches);

            $this->provider($file, $logger);
            $errors = array_filter($records->getRecords(), static fn ($r) => $r->level === Level::Error);
            self::assertCount(1, $errors, 'logged once per process');
            self::assertStringContainsString('ZITADEL_JWKS_FILE', array_values($errors)[0]->message);
        } finally {
            @unlink($file);
        }
    }

    public function testJwksFileIsUsedOutsideProduction(): void
    {
        $_ENV['APP_ENV'] = 'test';
        $other           = new OidcTestIssuer('kid-from-file');
        $file            = $other->writeJwksFile();
        try {
            self::assertArrayHasKey('kid-from-file', $this->provider($file)->keysFor('kid-from-file'));
            self::assertSame(0, $this->fetches);
        } finally {
            @unlink($file);
        }
    }
}
