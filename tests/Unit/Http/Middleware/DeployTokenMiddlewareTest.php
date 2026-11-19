<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Middleware;

use BibleGet\Api\Http\Middleware\DeployTokenMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DeployTokenMiddlewareTest extends TestCase
{
    private const string TOKEN = 'a-long-random-deploy-token-for-the-tests';

    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'DEPLOY_TOKEN'] as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function call(string $appEnv, string $token, ?string $header): ResponseInterface
    {
        $_ENV['APP_ENV']      = $appEnv;
        $_ENV['DEPLOY_TOKEN'] = $token;
        $request              = new ServerRequest('POST', '/_ops/migrate');
        if ($header !== null) {
            $request = $request->withHeader(DeployTokenMiddleware::HEADER, $header);
        }
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'migrated');
            }
        };
        return ( new DeployTokenMiddleware() )->process($request, $handler);
    }

    /** @return iterable<string, array{string, string, ?string, int}> */
    public static function cases(): iterable
    {
        yield 'staging, right token'            => ['staging', self::TOKEN, self::TOKEN, 200];
        yield 'production, right token'         => ['production', self::TOKEN, self::TOKEN, 200];
        yield 'development is disabled'         => ['development', self::TOKEN, self::TOKEN, 503];
        yield 'no token configured'             => ['staging', '', '', 503];
        yield 'missing header'                  => ['staging', self::TOKEN, null, 401];
        yield 'wrong token'                     => ['staging', self::TOKEN, 'not-the-token', 401];
        yield 'a prefix of the token is wrong'  => ['staging', self::TOKEN, substr(self::TOKEN, 0, 10), 401];
    }

    #[DataProvider('cases')]
    public function testOnlyTheRightTokenOnStagingOrProductionPasses(string $appEnv, string $token, ?string $header, int $status): void
    {
        $response = $this->call($appEnv, $token, $header);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($status === 200 ? 'migrated' : '', $status === 200 ? (string) $response->getBody() : '');
    }
}
