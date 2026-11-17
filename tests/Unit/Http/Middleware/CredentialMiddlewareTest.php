<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Middleware;

use BibleGet\Api\Http\Exception\UnauthorizedException;
use BibleGet\Api\Http\Middleware\CredentialMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CredentialMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'REQUIRE_CREDENTIALS'] as $k) {
            $this->savedEnv[$k] = $_ENV[$k] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
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
}
