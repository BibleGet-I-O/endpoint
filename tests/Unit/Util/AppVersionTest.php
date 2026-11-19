<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Util\AppVersion;
use PHPUnit\Framework\TestCase;

class AppVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/appversion-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        AppVersion::reset();
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/VERSION');
        @rmdir($this->root);
        AppVersion::reset();
    }

    public function testReadsAndTrimsVersionFile(): void
    {
        file_put_contents($this->root . '/VERSION', "v3.0.1-415-g892935b\n");
        self::assertSame('v3.0.1-415-g892935b', AppVersion::read($this->root));
    }

    public function testAcceptsFullCommitSha(): void
    {
        $sha = '8afaf1d3c5b2e7a94f06d1b8e3a7c2f5094d6b1e';
        self::assertSame(40, strlen($sha));
        file_put_contents($this->root . '/VERSION', $sha . "\n");
        self::assertSame($sha, AppVersion::read($this->root));
    }

    public function testAbsentFileFallsBackToDev(): void
    {
        self::assertSame('dev', AppVersion::read($this->root));
    }

    public function testInvalidCharactersFallBackToDev(): void
    {
        foreach (["v4.0.0\r\nX-Evil: 1", 'v4 0', 'v4.0.0<script>', '', str_repeat('a', 65)] as $bad) {
            file_put_contents($this->root . '/VERSION', $bad);
            self::assertSame('dev', AppVersion::read($this->root), $bad);
        }
    }

    public function testMaxLengthAccepted(): void
    {
        file_put_contents($this->root . '/VERSION', str_repeat('a', 64));
        self::assertSame(str_repeat('a', 64), AppVersion::read($this->root));
    }

    public function testCurrentIsCachedString(): void
    {
        self::assertSame(AppVersion::current(), AppVersion::current());
    }
}
