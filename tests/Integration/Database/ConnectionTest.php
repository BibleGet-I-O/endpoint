<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Api\Database\Connection;
use BibleGet\Tests\Integration\DatabaseTestCase;

class ConnectionTest extends DatabaseTestCase
{
    public function testGetConnectionReturnsMysqli(): void
    {
        $mysqli = Connection::getConnection();
        self::assertInstanceOf(\mysqli::class, $mysqli);
        self::assertSame(0, $mysqli->connect_errno);
    }

    public function testGetConnectionReturnsSingleton(): void
    {
        $c1 = Connection::getConnection();
        $c2 = Connection::getConnection();
        self::assertSame($c1, $c2);
    }

    public function testResetClearsSingleton(): void
    {
        $c1 = Connection::getConnection();
        Connection::reset();
        $c2 = Connection::getConnection();
        self::assertNotSame($c1, $c2);
    }

    public function testGetWhitelistedDomainsIPsReturnsArray(): void
    {
        Connection::getConnection(); // ensure initialized
        $result = Connection::getWhitelistedDomainsIPs();
        self::assertIsArray($result);
    }

    public function testCharsetIsUtf8mb4(): void
    {
        $mysqli = Connection::getConnection();
        self::assertSame('utf8mb4', $mysqli->character_set_name());
    }
}
