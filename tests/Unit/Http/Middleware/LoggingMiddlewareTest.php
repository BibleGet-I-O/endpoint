<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Middleware;

use BibleGet\Api\Http\Middleware\LoggingMiddleware;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
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

        $request = ( new ServerRequest('GET', '/test') )
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

        $request = ( new ServerRequest('POST', '/create') )
            ->withAttribute('request_id', 'abc');

        $response = $middleware->process($request, $handler);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('value', $response->getHeaderLine('X-Custom'));
    }

    public function testDebugLogRedactsCredentialsAndContactEmail(): void
    {
        $key      = 'bibleget_test_' . str_repeat('ab', 16);
        $token    = 'bginst_test_' . str_repeat('cd', 16);
        $client   = 'bgclient_test_' . str_repeat('ef', 16);
        $records  = new TestHandler();
        $logger   = new Logger('test', [$records]);
        $middle   = new LoggingMiddleware(true, $logger);
        $response = json_encode(['key' => $key, 'nested' => [['token' => $token]], 'name' => 'kept'], JSON_THROW_ON_ERROR);
        $handler  = new class ($response) implements RequestHandlerInterface {
            public function __construct(private string $body)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(201, ['Content-Type' => 'application/json'], $this->body);
            }
        };

        $request = ( new ServerRequest('PATCH', '/installations/self', [
            'Content-Type'  => 'application/json',
            'X-Api-Key'     => $client,
            'Authorization' => 'Bearer secret.jwt.value',
        ], json_encode(['contact_email' => 'someone@example.com', 'note' => "plain {$client}", 'glued' => "__{$client}__x{$key}9"], JSON_THROW_ON_ERROR)) );

        $out = $middle->process($request, $handler);
        self::assertSame($response, (string) $out->getBody(), 'the response itself is untouched');

        $logged = '';
        foreach ($records->getRecords() as $record) {
            $logged .= $record->message . ' ' . json_encode($record->context, JSON_THROW_ON_ERROR) . "\n";
        }
        self::assertStringContainsString('kept', $logged);
        foreach ([$key, $token, $client, 'someone@example.com', 'secret.jwt.value'] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
    }
}
