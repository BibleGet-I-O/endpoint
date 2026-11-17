<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit;

use BibleGet\Api\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testIsLocalhostReturnsBool(): void
    {
        // In test environment, SERVER_ADDR etc. may not be set
        // but the method should not throw
        $result = Router::isLocalhost();
        self::assertIsBool($result);
    }

    /**
     * @return array<string, array{array<string, string>, bool}>
     */
    public static function localhostProvider(): array
    {
        return [
            'SERVER_ADDR 127.0.0.1' => [['SERVER_ADDR' => '127.0.0.1'], true],
            'SERVER_ADDR ::1'       => [['SERVER_ADDR' => '::1'], true],
            'SERVER_ADDR 0.0.0.0'   => [['SERVER_ADDR' => '0.0.0.0'], true],
            'REMOTE_ADDR 127.0.0.1' => [['REMOTE_ADDR' => '127.0.0.1'], true],
            'REMOTE_ADDR ::1'       => [['REMOTE_ADDR' => '::1'], true],
            'SERVER_NAME localhost' => [['SERVER_NAME' => 'localhost'], true],
            'external SERVER_ADDR'  => [['SERVER_ADDR' => '192.168.1.1'], false],
            'external REMOTE_ADDR'  => [['REMOTE_ADDR' => '10.0.0.5'], false],
            'external SERVER_NAME'  => [['SERVER_NAME' => 'example.com'], false],
            'empty params'          => [[], false],
        ];
    }

    /**
     * @param array<string, string> $serverParams
     */
    #[DataProvider('localhostProvider')]
    public function testIsLocalhostDetection(array $serverParams, bool $expected): void
    {
        self::assertSame($expected, Router::isLocalhost($serverParams));
    }

    /**
     * @return array<string, array{string, list<string>, ?string}>
     */
    public static function dataRouteProvider(): array
    {
        return [
            'root alias of quote'  => ['', [], 'quote'],
            'quote'                => ['quote', [], 'quote'],
            'metadata collapses'   => ['metadata', ['bibleversions'], 'metadata'],
            'search alias'         => ['search', [], 'search'],
            'search keyword'       => ['search', ['keyword'], 'search/keyword'],
            'search similar'       => ['search', ['similar', 'x'], 'search/similar'],
            'unknown search route' => ['search', ['anything'], null],
            'unknown route'        => ['installations', [], null],
        ];
    }

    /**
     * @param list<string> $parts
     */
    #[DataProvider('dataRouteProvider')]
    public function testDataRouteName(string $route, array $parts, ?string $expected): void
    {
        self::assertSame($expected, Router::dataRouteName($route, $parts));
    }
}
