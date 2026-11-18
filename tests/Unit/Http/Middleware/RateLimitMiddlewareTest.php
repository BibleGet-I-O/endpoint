<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Middleware;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Http\Exception\TooManyRequestsException;
use BibleGet\Api\Http\Middleware\RateLimitMiddleware;
use BibleGet\Api\Services\RateLimiter;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RateLimitMiddlewareTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bibleget-ratelimit-mw-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private static function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    private function process(Credential $credential): ResponseInterface
    {
        $request = ( new ServerRequest('GET', '/v3/quote') )->withAttribute('credential', $credential);
        return ( new RateLimitMiddleware(new RateLimiter($this->dir)) )->process($request, self::handler());
    }

    private static function credential(bool $isSystem, int $perHour): Credential
    {
        return new Credential(CredentialType::Project, 'key-' . ( $isSystem ? 'system' : 'plain' ), 'read', $perHour, 'app', null, null, $isSystem);
    }

    public function testSystemCredentialIsNeverLimited(): void
    {
        $system = self::credential(true, 1);
        for ($i = 0; $i < 3; $i++) {
            $response = $this->process($system);
            self::assertSame(200, $response->getStatusCode());
            self::assertFalse($response->hasHeader('X-RateLimit-Limit'));
        }
        self::assertSame([], glob($this->dir . '/*') ?: [], 'nothing is recorded for a system credential');
    }

    public function testOrdinaryCredentialIsLimitedWithHeaders(): void
    {
        $plain    = self::credential(false, 1);
        $response = $this->process($plain);
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));

        try {
            $this->process($plain);
            self::fail('the second request in the hour is refused');
        } catch (TooManyRequestsException $e) {
            self::assertSame(429, $e->getStatus());
            self::assertGreaterThan(0, $e->getRetryAfter());
            self::assertSame('1', $e->getHeaders()['X-RateLimit-Limit'] ?? null);
        }
    }
}
