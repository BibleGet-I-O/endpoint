<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Server;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewareQueue = [];

    private RequestHandlerInterface $defaultHandler;

    public function __construct(RequestHandlerInterface $defaultHandler)
    {
        $this->defaultHandler = $defaultHandler;
    }

    public function pipe(MiddlewareInterface $middleware): void
    {
        $this->middlewareQueue[] = $middleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $queue = $this->middlewareQueue;
        return self::dispatch($request, $queue, $this->defaultHandler);
    }

    /**
     * @param MiddlewareInterface[] $queue
     */
    private static function dispatch(ServerRequestInterface $request, array $queue, RequestHandlerInterface $fallback): ResponseInterface
    {
        if (empty($queue)) {
            return $fallback->handle($request);
        }

        $middleware = array_shift($queue);
        $next = new class($queue, $fallback) implements RequestHandlerInterface {
            /** @param MiddlewareInterface[] $queue */
            public function __construct(
                private array $queue,
                private RequestHandlerInterface $fallback
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return MiddlewarePipeline::dispatchStatic($request, $this->queue, $this->fallback);
            }
        };

        return $middleware->process($request, $next);
    }

    /**
     * @param MiddlewareInterface[] $queue
     * @internal Used by the anonymous handler class
     */
    public static function dispatchStatic(ServerRequestInterface $request, array $queue, RequestHandlerInterface $fallback): ResponseInterface
    {
        return self::dispatch($request, $queue, $fallback);
    }
}
