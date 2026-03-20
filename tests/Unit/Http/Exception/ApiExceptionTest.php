<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Exception;

use BibleGet\Api\Http\Exception\ApiException;
use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\MethodNotAllowedException;
use BibleGet\Api\Http\Exception\NotAcceptableException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\TooManyRequestsException;
use BibleGet\Api\Http\Exception\UnsupportedMediaTypeException;
use BibleGet\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiExceptionTest extends TestCase
{
    /**
     * @return array<string, array{class-string<ApiException>, int, string}>
     */
    public static function exceptionProvider(): array
    {
        return [
            'BadRequest'            => [BadRequestException::class, 400, 'Bad Request'],
            'NotFound'              => [NotFoundException::class, 404, 'Not Found'],
            'MethodNotAllowed'      => [MethodNotAllowedException::class, 405, 'Method Not Allowed'],
            'NotAcceptable'         => [NotAcceptableException::class, 406, 'Not Acceptable'],
            'UnsupportedMediaType'  => [UnsupportedMediaTypeException::class, 415, 'Unsupported Media Type'],
            'Validation'            => [ValidationException::class, 422, 'Unprocessable Content'],
            'InternalServerError'   => [InternalServerErrorException::class, 500, 'Internal Server Error'],
        ];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('exceptionProvider')]
    public function testExceptionStatusAndTitle(string $class, int $expectedStatus, string $expectedTitle): void
    {
        $exception = new $class();
        self::assertSame($expectedStatus, $exception->getStatus());
        self::assertSame($expectedTitle, $exception->getTitle());
        self::assertNotEmpty($exception->getType());
        self::assertStringStartsWith('https://', $exception->getType());
    }

    #[DataProvider('exceptionProvider')]
    public function testToArrayWithoutDebug(string $class, int $expectedStatus, string $expectedTitle): void
    {
        $exception = new $class('Test message');
        $array = $exception->toArray(false);

        self::assertArrayHasKey('type', $array);
        self::assertArrayHasKey('title', $array);
        self::assertArrayHasKey('status', $array);
        self::assertArrayHasKey('detail', $array);
        self::assertSame($expectedStatus, $array['status']);
        self::assertSame($expectedTitle, $array['title']);
        self::assertSame('Test message', $array['detail']);
        self::assertArrayNotHasKey('file', $array);
        self::assertArrayNotHasKey('line', $array);
        self::assertArrayNotHasKey('trace', $array);
    }

    public function testToArrayWithDebug(): void
    {
        $exception = new BadRequestException('Debug test');
        $array = $exception->toArray(true);

        self::assertArrayHasKey('file', $array);
        self::assertArrayHasKey('line', $array);
        self::assertArrayHasKey('trace', $array);
    }

    public function testTooManyRequestsRetryAfter(): void
    {
        $exception = new TooManyRequestsException('Rate limited', 120);
        self::assertSame(429, $exception->getStatus());
        self::assertSame(120, $exception->getRetryAfter());

        $array = $exception->toArray(false);
        self::assertArrayHasKey('retryAfter', $array);
        self::assertSame(120, $array['retryAfter']);
    }

    public function testTooManyRequestsZeroRetryAfter(): void
    {
        $exception = new TooManyRequestsException('Rate limited', 0);
        self::assertSame(0, $exception->getRetryAfter());

        $array = $exception->toArray(false);
        self::assertArrayNotHasKey('retryAfter', $array);
    }

    public function testCustomMessage(): void
    {
        $exception = new NotFoundException('Page /foo not found');
        self::assertSame('Page /foo not found', $exception->getMessage());
        self::assertSame('Page /foo not found', $exception->toArray()['detail']);
    }

    public function testPreviousException(): void
    {
        $previous = new \RuntimeException('Original');
        $exception = new InternalServerErrorException('Wrapped', $previous);
        self::assertSame($previous, $exception->getPrevious());
    }
}
