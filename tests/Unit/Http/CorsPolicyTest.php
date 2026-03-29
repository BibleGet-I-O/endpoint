<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http;

use BibleGet\Api\Http\CorsPolicy;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class CorsPolicyTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        unset($_ENV['CORS_ALLOWED_ORIGINS']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['CORS_ALLOWED_ORIGINS']);
    }

    private function request(string $origin = ''): ServerRequest
    {
        $req = new ServerRequest('GET', 'http://example.com/v3/quote');
        return $origin !== '' ? $req->withHeader('Origin', $origin) : $req;
    }

    public function testNoOriginReturnsWildcard(): void
    {
        $response = ( new CorsPolicy() )->applyHeaders(
            $this->request(),
            $this->factory->createResponse(200)
        );

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testAnyOriginAllowedWhenNoAllowlistConfigured(): void
    {
        $response = ( new CorsPolicy() )->applyHeaders(
            $this->request('https://any-site.example'),
            $this->factory->createResponse(200)
        );

        $this->assertSame('https://any-site.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testAllowedOriginGetsCredentials(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://app.bibleget.io,https://staging.bibleget.io';

        $response = ( new CorsPolicy() )->applyHeaders(
            $this->request('https://app.bibleget.io'),
            $this->factory->createResponse(200)
        );

        $this->assertSame('https://app.bibleget.io', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testUnknownOriginDoesNotGetCredentials(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://app.bibleget.io';

        $response = ( new CorsPolicy() )->applyHeaders(
            $this->request('https://evil.example'),
            $this->factory->createResponse(200)
        );

        $this->assertSame('https://evil.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testAllowlistEntriesAreTrimmed(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = '  https://app.bibleget.io  ,  https://staging.bibleget.io  ';

        $response = ( new CorsPolicy() )->applyHeaders(
            $this->request('https://staging.bibleget.io'),
            $this->factory->createResponse(200)
        );

        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }
}
