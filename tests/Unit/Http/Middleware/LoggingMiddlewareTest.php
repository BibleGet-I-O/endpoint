<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Middleware;

use BibleGet\Api\Http\Middleware\LoggingMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LoggingMiddlewareTest extends TestCase
{
    public function testPassesThroughRequest(): void
    {
        $middleware = new LoggingMiddleware(false);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
            }
        };

        $request = (new ServerRequest('GET', '/test'))
            ->withAttribute('request_id', 'test-123');

        $response = $middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testDoesNotAlterResponse(): void
    {
        $middleware = new LoggingMiddleware(true);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(201, ['X-Custom' => 'value'], 'created');
            }
        };

        $request = (new ServerRequest('POST', '/create'))
            ->withAttribute('request_id', 'abc');

        $response = $middleware->process($request, $handler);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('value', $response->getHeaderLine('X-Custom'));
    }
}
