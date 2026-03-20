<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Logs;

use BibleGet\Api\Http\Logs\LoggerFactory;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class LoggerFactoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/bibleget-test-logs-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp log files
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testCreateReturnsLogger(): void
    {
        $logger = LoggerFactory::create('test-unit', $this->tempDir, 5, false, false);
        self::assertInstanceOf(Logger::class, $logger);
    }

    public function testCreateReturnsSameInstance(): void
    {
        $logger1 = LoggerFactory::create('singleton-test', $this->tempDir, 5, false, false);
        $logger2 = LoggerFactory::create('singleton-test', $this->tempDir, 5, false, false);
        self::assertSame($logger1, $logger2);
    }

    public function testLoggerCanWrite(): void
    {
        $logger = LoggerFactory::create('write-test', $this->tempDir, 5, true, false);
        $logger->info('Test message');

        $files = glob($this->tempDir . '/write-test*.log');
        self::assertNotFalse($files);
        self::assertNotEmpty($files, 'A log file should have been created');
    }
}
