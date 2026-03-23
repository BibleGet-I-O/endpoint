<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration;

use BibleGet\Api\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that require a database connection.
 *
 * Database credentials are loaded from .env.test by the test bootstrap.
 * Resets Connection singleton between tests and cleans transient data
 * (counter, request logs) after each test.
 */
abstract class DatabaseTestCase extends TestCase
{
    private string $testYear;

    protected function setUp(): void
    {
        $this->testYear = date('Y');

        // Reset singleton so each test starts fresh
        Connection::reset();

        // Verify DB is reachable
        try {
            Connection::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Test database not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Clean transient tables using the year captured at setUp
        try {
            $pdo = Connection::getConnection();
            $pdo->exec('UPDATE counter SET good = 0, bad = 0');
            $pdo->exec('DELETE FROM requests_log__' . $this->testYear);
            $pdo->exec('DELETE FROM curl_error');
        } catch (\Throwable) {
            // DB not available (test was skipped), nothing to clean
        }

        Connection::reset();
    }

    protected function getConnection(): \PDO
    {
        return Connection::getConnection();
    }
}
