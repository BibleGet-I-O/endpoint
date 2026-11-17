<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Services\RateLimiter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private string $dir;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bibleget-ratelimiter-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testSlidingWindow(): void
    {
        $limiter = new RateLimiter($this->dir, 3600, null, fn (): int => $this->now);

        $first = $limiter->hit('project:a', 2);
        self::assertSame(['allowed' => true, 'remaining' => 1, 'reset' => $this->now + 3600], $first);

        $this->now += 600;
        $second     = $limiter->hit('project:a', 2);
        self::assertTrue($second['allowed']);
        self::assertSame(0, $second['remaining']);
        // The window frees its first slot when the oldest hit expires
        self::assertSame(1_800_000_000 + 3600, $second['reset']);

        $third = $limiter->hit('project:a', 2);
        self::assertFalse($third['allowed']);
        self::assertSame(0, $third['remaining']);

        // Another identifier has its own window
        self::assertTrue($limiter->hit('installation:a', 2)['allowed']);

        // Once the first hit leaves the window, one more request is allowed
        $this->now = 1_800_000_000 + 3601;
        $fourth    = $limiter->hit('project:a', 2);
        self::assertTrue($fourth['allowed']);
        self::assertSame(0, $fourth['remaining']);
        self::assertFalse($limiter->hit('project:a', 2)['allowed']);
    }

    public function testWindowIsEvaluatedAtTheTimeTheLockIsHeld(): void
    {
        $tick    = 0;
        $limiter = new RateLimiter($this->dir, 3600, null, function () use (&$tick): int {
            $t          = $this->now;
            $this->now += $tick; // time that passes on every read, e.g. while waiting for the lock
            return $t;
        });
        $start   = $this->now;
        self::assertTrue($limiter->hit('project:slow', 1)['allowed']);

        // Ten seconds before the first hit expires; the lock then takes twenty.
        $this->now = $start + 3590;
        $tick      = 20;
        $result    = $limiter->hit('project:slow', 1);
        self::assertTrue($result['allowed'], 'the expired hit no longer counts once the lock is acquired');
        self::assertGreaterThan($start + 3600, $result['reset']);
    }

    public function testLimiterFailsOpenWhenStorageUnwritable(): void
    {
        $handler = new TestHandler();
        $limiter = new RateLimiter('/proc/nonexistent', 3600, new Logger('test', [$handler]), fn (): int => $this->now);

        $result = $limiter->hit('project:a', 2);
        self::assertTrue($result['allowed']);
        self::assertSame(2, $result['remaining']);
        self::assertTrue($handler->hasErrorRecords());

        // Still open on later hits: the limiter never blocks without storage
        $limiter->hit('project:a', 2);
        self::assertTrue($limiter->hit('project:a', 2)['allowed']);
    }
}
