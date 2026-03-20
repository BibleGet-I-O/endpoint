<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Enum;

use BibleGet\Api\Http\Enum\RequestContentType;
use PHPUnit\Framework\TestCase;

class RequestContentTypeTest extends TestCase
{
    public function testJsonValue(): void
    {
        self::assertSame('application/json', RequestContentType::JSON->value);
    }

    public function testFormDataValue(): void
    {
        self::assertSame('application/x-www-form-urlencoded', RequestContentType::FORMDATA->value);
    }

    public function testTryFromValid(): void
    {
        self::assertSame(RequestContentType::JSON, RequestContentType::tryFrom('application/json'));
    }

    public function testTryFromInvalid(): void
    {
        self::assertNull(RequestContentType::tryFrom('text/plain'));
    }
}
