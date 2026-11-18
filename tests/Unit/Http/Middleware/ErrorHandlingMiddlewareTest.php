<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Middleware;

use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\TooManyRequestsException;
use BibleGet\Api\Http\Middleware\ErrorHandlingMiddleware;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ErrorHandlingMiddlewareTest extends TestCase
{
    private ErrorHandlingMiddleware $middleware;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory    = new Psr17Factory();
        $this->middleware = new ErrorHandlingMiddleware($this->factory, true);
    }

    protected function tearDown(): void
    {
        // ErrorHandlingMiddleware registers set_error_handler and set_exception_handler.
        // PHPUnit detects leftover handlers as risky. Restore them.
        restore_error_handler();
        restore_exception_handler();

        // Reset the static guard so the next test's constructor re-registers
        // handlers (otherwise tearDown would pop PHPUnit's own handlers).
        $ref = new \ReflectionProperty(ErrorHandlingMiddleware::class, 'handlersRegistered');
        $ref->setValue(null, false);
    }

    public function testPassesThroughOnSuccess(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
            }
        };

        $response = $this->middleware->process(new ServerRequest('GET', '/'), $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testCatchesApiException(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new NotFoundException('Not here');
            }
        };

        $response = $this->middleware->process(new ServerRequest('GET', '/test'), $handler);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(404, $body['status']);
        self::assertSame('Not Found', $body['title']);
        self::assertSame('Not here', $body['detail']);
        self::assertStringContainsString('rfc9110', $body['type']);
    }

    public function testCatchesGenericException(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Something broke');
            }
        };

        $response = $this->middleware->process(new ServerRequest('GET', '/'), $handler);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(500, $body['status']);
        // In debug mode, the original message is shown
        self::assertSame('Something broke', $body['detail']);
    }

    public function testGenericExceptionHidesDetailInProduction(): void
    {
        // Reset static guard so this non-debug instance registers its own handlers
        $ref = new \ReflectionProperty(ErrorHandlingMiddleware::class, 'handlersRegistered');
        $ref->setValue(null, false);
        restore_error_handler();
        restore_exception_handler();

        $middleware = new ErrorHandlingMiddleware($this->factory, false);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Secret internal error');
            }
        };

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);
        $body     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('An unexpected error occurred.', $body['detail']);
    }

    public function testDebugModeIncludesTraceForApiException(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new BadRequestException('Bad');
            }
        };

        $response = $this->middleware->process(new ServerRequest('GET', '/'), $handler);
        $body     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('file', $body);
        self::assertArrayHasKey('line', $body);
        self::assertArrayHasKey('trace', $body);
    }

    public function testCorsHeaderWithOrigin(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new NotFoundException();
            }
        };

        $request  = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);
        $response = $this->middleware->process($request, $handler);

        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testCorsHeaderWithoutOrigin(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new NotFoundException();
            }
        };

        $response = $this->middleware->process(new ServerRequest('GET', '/'), $handler);
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testTooManyRequestsRetryAfterHeader(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new TooManyRequestsException('Slow down', 60);
            }
        };

        $response = $this->middleware->process(new ServerRequest('GET', '/'), $handler);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
    }

    private static function throwing(\Throwable $e): RequestHandlerInterface
    {
        return new class ($e) implements RequestHandlerInterface {
            public function __construct(private \Throwable $e)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->e;
            }
        };
    }

    public function testClientErrorsAreLoggedAtInfoWithoutTrace(): void
    {
        $records    = new TestHandler();
        $middleware = new ErrorHandlingMiddleware($this->factory, false, new Logger('test', [$records]));

        $response = $middleware->process(new ServerRequest('GET', '/v3/quote'), self::throwing(new NotFoundException('No such thing')));
        self::assertSame(404, $response->getStatusCode());

        $logged = $records->getRecords();
        self::assertCount(1, $logged);
        self::assertSame(Level::Info, $logged[0]->level);
        self::assertStringContainsString('No such thing', $logged[0]->message);
        self::assertStringNotContainsString('Stack trace', $logged[0]->message);
        self::assertArrayNotHasKey('exception', $logged[0]->context);
    }

    public function testServerErrorsStayAtErrorWithTrace(): void
    {
        $records    = new TestHandler();
        $middleware = new ErrorHandlingMiddleware($this->factory, false, new Logger('test', [$records]));

        $response = $middleware->process(new ServerRequest('GET', '/v3/quote'), self::throwing(new \RuntimeException('boom')));
        self::assertSame(500, $response->getStatusCode());

        $logged = $records->getRecords();
        self::assertCount(1, $logged);
        self::assertSame(Level::Error, $logged[0]->level);
        self::assertStringContainsString('Stack trace', $logged[0]->message);
        // The exception object would be serialized with its unmasked message.
        self::assertArrayNotHasKey('exception', $logged[0]->context);
        self::assertSame(\RuntimeException::class, $logged[0]->context['exception_class'] ?? null);
    }

    public function testLoggedUriHasCredentialsMasked(): void
    {
        $key        = 'bibleget_test_' . str_repeat('ab', 16);
        $records    = new TestHandler();
        $middleware = new ErrorHandlingMiddleware($this->factory, false, new Logger('test', [$records]));

        foreach ([new NotFoundException('No such thing'), new \RuntimeException('boom', 0, new \RuntimeException('upstream said ' . $key))] as $e) {
            $request = new ServerRequest('GET', '/v3/quote?query=Jn3,16&api_key=' . $key);
            $middleware->process($request, self::throwing($e));
            self::assertStringContainsString('api_key=', (string) $request->getUri(), 'the request itself is untouched');
        }

        $logged = $records->getRecords();
        self::assertCount(2, $logged);
        foreach ($logged as $record) {
            self::assertStringContainsString('/v3/quote?query=Jn3,16&api_key=[redacted]', $record->message);
            self::assertStringNotContainsString($key, $record->message);
            self::assertStringNotContainsString($key, json_encode($record->context, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        }
    }
}
