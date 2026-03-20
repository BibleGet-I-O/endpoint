<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit;

use BibleGet\Api\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testIsLocalhostDetection(): void
    {
        // In test environment, SERVER_ADDR etc. may not be set
        // but the method should not throw
        $result = Router::isLocalhost();
        self::assertIsBool($result);
    }
}
