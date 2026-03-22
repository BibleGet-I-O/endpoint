<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Enum;

use BibleGet\Api\Http\Enum\StatusCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusCodeTest extends TestCase
{
    /**
     * @return array<string, array{StatusCode, int, string}>
     */
    public static function statusCodeProvider(): array
    {
        return [
            'OK'                     => [StatusCode::OK, 200, 'OK'],
            'No Content'             => [StatusCode::NO_CONTENT, 204, 'No Content'],
            'Bad Request'            => [StatusCode::BAD_REQUEST, 400, 'Bad Request'],
            'Not Found'              => [StatusCode::NOT_FOUND, 404, 'Not Found'],
            'Method Not Allowed'     => [StatusCode::METHOD_NOT_ALLOWED, 405, 'Method Not Allowed'],
            'Not Acceptable'         => [StatusCode::NOT_ACCEPTABLE, 406, 'Not Acceptable'],
            'Unsupported Media Type' => [StatusCode::UNSUPPORTED_MEDIA_TYPE, 415, 'Unsupported Media Type'],
            'Unprocessable Content'  => [StatusCode::UNPROCESSABLE_CONTENT, 422, 'Unprocessable Content'],
            'Too Many Requests'      => [StatusCode::TOO_MANY_REQUESTS, 429, 'Too Many Requests'],
            'Internal Server Error'  => [StatusCode::INTERNAL_SERVER_ERROR, 500, 'Internal Server Error'],
        ];
    }

    #[DataProvider('statusCodeProvider')]
    public function testValueAndReason(StatusCode $code, int $expectedValue, string $expectedReason): void
    {
        self::assertSame($expectedValue, $code->value);
        self::assertSame($expectedReason, $code->reason());
    }

    public function testAllCasesHaveReasons(): void
    {
        foreach (StatusCode::cases() as $case) {
            self::assertNotEmpty($case->reason(), "StatusCode::{$case->name} should have a non-empty reason");
        }
    }
}
