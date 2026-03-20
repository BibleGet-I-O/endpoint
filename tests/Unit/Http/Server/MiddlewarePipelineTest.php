<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Server;

use BibleGet\Api\Http\Server\MiddlewarePipeline;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewarePipelineTest extends TestCase
{
    public function testEmptyPipelineFallsToHandler(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'handler');
            }
        };

        $pipeline = new MiddlewarePipeline($handler);
        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('handler', (string) $response->getBody());
    }

    public function testMiddlewareIsExecuted(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'handler');
            }
        };

        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                return $response->withHeader('X-Middleware', 'applied');
            }
        };

        $pipeline = new MiddlewarePipeline($handler);
        $pipeline->pipe($middleware);
        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame('applied', $response->getHeaderLine('X-Middleware'));
        self::assertSame('handler', (string) $response->getBody());
    }

    public function testMiddlewareExecutionOrder(): void
    {
        $log = [];

        $handler = new class ($log) implements RequestHandlerInterface {
            /** @var array<int, string> */
            private array $log;

            /**
             * @param array<int, string> $log
             */
            public function __construct(array &$log)
            {
                $this->log = &$log;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->log[] = 'handler';
                return new Response(200);
            }
        };

        $makeMiddleware = function (string $name) use (&$log): MiddlewareInterface {
            return new class ($name, $log) implements MiddlewareInterface {
                private string $name;
                /** @var array<int, string> */
                private array $log;

                /**
                 * @param array<int, string> $log
                 */
                public function __construct(string $name, array &$log)
                {
                    $this->name = $name;
                    $this->log = &$log;
                }

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $this->log[] = $this->name . ':before';
                    $response = $handler->handle($request);
                    $this->log[] = $this->name . ':after';
                    return $response;
                }
            };
        };

        $pipeline = new MiddlewarePipeline($handler);
        $pipeline->pipe($makeMiddleware('outer'));
        $pipeline->pipe($makeMiddleware('inner'));
        $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame(['outer:before', 'inner:before', 'handler', 'inner:after', 'outer:after'], $log);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'should not reach');
            }
        };

        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(403, [], 'blocked');
            }
        };

        $pipeline = new MiddlewarePipeline($handler);
        $pipeline->pipe($middleware);
        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('blocked', (string) $response->getBody());
    }
}
