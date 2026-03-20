<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration;

use BibleGet\Api\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that require a database connection.
 *
 * Loads test credentials, resets Connection singleton between tests,
 * and cleans transient data (counter, request logs) after each test.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static bool $credentialsLoaded = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$credentialsLoaded) {
            require_once __DIR__ . '/../fixtures/dbcredentials.php';
            self::$credentialsLoaded = true;
        }
    }

    protected function setUp(): void
    {
        // Reset singleton so each test starts fresh
        Connection::reset();

        // Verify DB is reachable
        $mysqli = Connection::getConnection();
        if ($mysqli->connect_errno) {
            self::markTestSkipped('Test database not available: ' . $mysqli->connect_error);
        }
    }

    protected function tearDown(): void
    {
        // Clean transient tables
        $mysqli = Connection::getConnection();
        $mysqli->query('UPDATE counter SET good = 0, bad = 0');
        $mysqli->query('DELETE FROM requests_log__2026');
        $mysqli->query('DELETE FROM curl_error');

        Connection::reset();
    }

    protected function getConnection(): \mysqli
    {
        return Connection::getConnection();
    }
}
