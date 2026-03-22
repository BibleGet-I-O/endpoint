<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Enum;

use BibleGet\Api\Http\Enum\AcceptHeader;
use PHPUnit\Framework\TestCase;

class AcceptHeaderTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('application/json', AcceptHeader::JSON->value);
        self::assertSame('application/xml', AcceptHeader::XML->value);
        self::assertSame('text/html', AcceptHeader::HTML->value);
    }

    public function testCaseCount(): void
    {
        self::assertCount(3, AcceptHeader::cases());
    }
}
