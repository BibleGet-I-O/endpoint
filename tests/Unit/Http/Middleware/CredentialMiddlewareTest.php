<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Middleware;

use BibleGet\Api\Http\Exception\UnauthorizedException;
use BibleGet\Api\Http\Middleware\CredentialMiddleware;
use BibleGet\Tests\Support\IsolatesEnvironment;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CredentialMiddlewareTest extends TestCase
{
    use IsolatesEnvironment;

    protected function setUp(): void
    {
        $this->isolateEnvironment(['APP_ENV', 'REQUIRE_CREDENTIALS']);
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();
    }

    private function recordingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;
                return new Response(200);
            }
        };
    }

    public function testProductionForcesRequireCredentials(): void
    {
        $_ENV['APP_ENV']             = 'production';
        $_ENV['REQUIRE_CREDENTIALS'] = 'false';

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessageMatches('/X-Api-Key/');
        ( new CredentialMiddleware() )->process(new ServerRequest('GET', '/v3/quote'), $this->recordingHandler());
    }

    public function testWithoutRequirementUnkeyedRequestPassesWithNullCredential(): void
    {
        $_ENV['APP_ENV'] = 'test';
        unset($_ENV['REQUIRE_CREDENTIALS']);

        $handler  = $this->recordingHandler();
        $response = ( new CredentialMiddleware() )->process(new ServerRequest('GET', '/v3/quote'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($handler->seen);
        self::assertArrayHasKey('credential', $handler->seen->getAttributes());
        self::assertNull($handler->seen->getAttribute('credential'));
    }

    public function testTestCredentialsAreRejectedInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';
        // A PDO that fails on any use proves the rejection needs no lookup.
        $noDb = new class extends \PDO {
            public function __construct()
            {
            }
        };
        foreach (['bibleget_test_', 'bginst_test_', 'bgclient_test_'] as $prefix) {
            $request = new ServerRequest('GET', '/v3/quote', ['X-Api-Key' => $prefix . str_repeat('a', 32)]);
            try {
                ( new CredentialMiddleware($noDb) )->process($request, $this->recordingHandler());
                self::fail($prefix . ' was accepted in production');
            } catch (UnauthorizedException $e) {
                self::assertSame(401, $e->getStatus());
            }
        }
    }
}
