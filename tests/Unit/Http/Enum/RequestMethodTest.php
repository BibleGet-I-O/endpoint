<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Enum;

use BibleGet\Api\Http\Enum\RequestMethod;
use PHPUnit\Framework\TestCase;

class RequestMethodTest extends TestCase
{
    public function testExpectedCases(): void
    {
        $values = array_column(RequestMethod::cases(), 'value');
        self::assertContains('GET', $values);
        self::assertContains('POST', $values);
        self::assertContains('OPTIONS', $values);
    }

    public function testCaseValues(): void
    {
        self::assertSame('GET', RequestMethod::GET->value);
        self::assertSame('POST', RequestMethod::POST->value);
        self::assertSame('OPTIONS', RequestMethod::OPTIONS->value);
    }
}
