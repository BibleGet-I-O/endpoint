<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Api\Database\Connection;
use BibleGet\Tests\Integration\DatabaseTestCase;

class ConnectionTest extends DatabaseTestCase
{
    public function testGetConnectionReturnsPdo(): void
    {
        $pdo = Connection::getConnection();
        self::assertInstanceOf(\PDO::class, $pdo);
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

    public function testClientEncodingIsUtf8(): void
    {
        $pdo      = Connection::getConnection();
        $encoding = $pdo->query('SHOW client_encoding')->fetchColumn();
        self::assertSame('UTF8', $encoding);
    }
}
