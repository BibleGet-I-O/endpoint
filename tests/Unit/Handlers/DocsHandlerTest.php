<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Handlers;

use BibleGet\Api\Handlers\DocsHandler;
use BibleGet\Api\Http\Exception\MethodNotAllowedException;
use BibleGet\Api\Router;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class DocsHandlerTest extends TestCase
{
    private string $originalApiBase;

    protected function setUp(): void
    {
        $this->originalApiBase = Router::$apiBase;
        Router::$apiBase       = '/v3/';
    }

    protected function tearDown(): void
    {
        Router::$apiBase = $this->originalApiBase;
    }

    public function testGetReturnsHtml(): void
    {
        $request  = new ServerRequest('GET', 'http://localhost/v3/docs');
        $response = ( new DocsHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('swagger-ui', $body);
        // Verify the spec path is correctly embedded in the JS config.
        self::assertStringContainsString('const specPath = "/v3/openapi.json"', $body);
    }

    public function testResponseIncludesCspHeader(): void
    {
        $request  = new ServerRequest('GET', 'http://localhost/v3/docs');
        $response = ( new DocsHandler() )->handle($request);

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("connect-src 'self' https://unpkg.com", $csp);
        self::assertStringContainsString('worker-src blob:', $csp);
        // Nonce must be present; 'unsafe-inline' must not be.
        self::assertStringContainsString("'nonce-", $csp);
        self::assertStringNotContainsString("'unsafe-inline'", $csp);
    }

    public function testNonceInCspMatchesNonceInHtml(): void
    {
        $request  = new ServerRequest('GET', 'http://localhost/v3/docs');
        $response = ( new DocsHandler() )->handle($request);

        $csp  = $response->getHeaderLine('Content-Security-Policy');
        $body = (string) $response->getBody();

        // Extract nonce value from CSP header.
        preg_match("/'nonce-([^']+)'/", $csp, $matches);
        self::assertNotEmpty($matches[1], 'Nonce not found in CSP header');
        $nonce = $matches[1];

        // The same nonce must appear on both the <style> and <script> tags.
        self::assertStringContainsString('nonce="' . $nonce . '"', $body);
    }

    public function testSwaggerUiDistIsPinnedToMajorVersion(): void
    {
        $request  = new ServerRequest('GET', 'http://localhost/v3/docs');
        $response = ( new DocsHandler() )->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('swagger-ui-dist@5', $body);
        // No unversioned unpkg URLs.
        self::assertStringNotContainsString('unpkg.com/swagger-ui-dist/', $body);
    }

    public function testSpecFetchErrorShowsFallbackMessage(): void
    {
        $request  = new ServerRequest('GET', 'http://localhost/v3/docs');
        $response = ( new DocsHandler() )->handle($request);

        $body = (string) $response->getBody();
        // The try/catch and error element must be present in the bootstrap script.
        self::assertStringContainsString('catch (err)', $body);
        self::assertStringContainsString('spec-load-error', $body);
    }

    public function testResponseIncludesXContentTypeOptions(): void
    {
        $request  = new ServerRequest('GET', 'http://localhost/v3/docs');
        $response = ( new DocsHandler() )->handle($request);

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testMethodNotAllowedForPost(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        $request = new ServerRequest('POST', 'http://localhost/v3/docs');
        ( new DocsHandler() )->handle($request);
    }

    public function testOptionsReturnsPreflight(): void
    {
        $request  = ( new ServerRequest('OPTIONS', 'http://localhost/v3/docs') )
            ->withHeader('Origin', 'https://example.com')
            ->withHeader('Access-Control-Request-Method', 'GET');
        $response = ( new DocsHandler() )->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testResponsePatchesServerUrlAndVersionAtRuntime(): void
    {
        $request  = new ServerRequest('GET', 'http://localhost/v3/docs');
        $response = ( new DocsHandler() )->handle($request);

        $body = (string) $response->getBody();
        // The spec's hard-coded production servers array must be replaced with
        // the runtime origin so the Servers dropdown and Try-it-out both hit
        // the server that served this page.
        self::assertStringContainsString('spec.servers', $body);
        self::assertStringContainsString('window.location.origin', $body);
        // Singular `version` parameter default must be patched.
        self::assertStringContainsString("param.name === 'version'", $body);
        self::assertStringContainsString('param.schema.default = firstVersion', $body);
        // Plural `versions` parameter default (arrays, e.g. versionindex) must also be patched.
        self::assertStringContainsString("param.name === 'versions'", $body);
        self::assertStringContainsString('param.schema.default = [firstVersion]', $body);
        self::assertStringContainsString('bibleversions', $body);
    }

    public function testResponseIncludesNoCacheHeader(): void
    {
        $request  = new ServerRequest('GET', 'http://localhost/v3/docs');
        $response = ( new DocsHandler() )->handle($request);

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testSpecUrlUsesApiBase(): void
    {
        Router::$apiBase = '/api/v3/';
        $request         = new ServerRequest('GET', 'http://localhost/api/v3/docs');
        $response        = ( new DocsHandler() )->handle($request);

        self::assertStringContainsString('const specPath = "/api/v3/openapi.json"', (string) $response->getBody());
    }
}
