<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Handlers;

use BibleGet\Api\Handlers\AbstractHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Api\Http\Exception\MethodNotAllowedException;
use BibleGet\Api\Http\Exception\NotAcceptableException;
use BibleGet\Api\Http\Exception\UnsupportedMediaTypeException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AbstractHandlerTest extends TestCase
{
    private function createHandler(): AbstractHandler
    {
        return new class extends AbstractHandler {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $params      = $this->getRequestParams($request);
                $contentType = $this->resolveResponseContentType($request, $params);
                $response    = $this->initResponse($request, $contentType);
                return $this->jsonResponse($response, ['params' => $params]);
            }

            // Expose protected methods for testing
            public function testValidateRequestMethod(ServerRequestInterface $request): void
            {
                $this->validateRequestMethod($request);
            }

            public function testValidateAcceptHeader(ServerRequestInterface $request): string
            {
                return $this->validateAcceptHeader($request);
            }

            public function testValidateRequestContentType(ServerRequestInterface $request): void
            {
                $this->validateRequestContentType($request);
            }

            public function testGetRequestParams(ServerRequestInterface $request): mixed
            {
                return $this->getRequestParams($request);
            }

            public function testHandlePreflightRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return $this->handlePreflightRequest($request, $response);
            }
        };
    }

    // -- Request method validation --

    public function testValidateRequestMethodAllowed(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedRequestMethods([RequestMethod::GET]);
        $handler->testValidateRequestMethod(new ServerRequest('GET', '/'));
        $this->addToAssertionCount(1);
    }

    public function testValidateRequestMethodNotAllowed(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedRequestMethods([RequestMethod::GET]);
        $this->expectException(MethodNotAllowedException::class);
        $handler->testValidateRequestMethod(new ServerRequest('DELETE', '/'));
    }

    // -- Accept header validation --

    public function testValidateAcceptHeaderEmpty(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedAcceptHeaders([AcceptHeader::JSON]);
        $result = $handler->testValidateAcceptHeader(new ServerRequest('GET', '/'));
        self::assertSame('application/json', $result);
    }

    public function testValidateAcceptHeaderWildcard(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedAcceptHeaders([AcceptHeader::XML, AcceptHeader::JSON]);
        $request = new ServerRequest('GET', '/', ['Accept' => '*/*']);
        $result  = $handler->testValidateAcceptHeader($request);
        self::assertSame('application/xml', $result); // first allowed
    }

    public function testValidateAcceptHeaderMatch(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML]);
        $request = new ServerRequest('GET', '/', ['Accept' => 'application/xml']);
        $result  = $handler->testValidateAcceptHeader($request);
        self::assertSame('application/xml', $result);
    }

    public function testValidateAcceptHeaderNotAcceptable(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedAcceptHeaders([AcceptHeader::JSON]);
        $request = new ServerRequest('GET', '/', ['Accept' => 'image/png']);
        $this->expectException(NotAcceptableException::class);
        $handler->testValidateAcceptHeader($request);
    }

    public function testValidateAcceptHeaderQValuePrecedence(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML]);
        $request = new ServerRequest('GET', '/', ['Accept' => 'application/xml;q=0.1, application/json;q=0.9']);
        $result  = $handler->testValidateAcceptHeader($request);
        self::assertSame('application/json', $result);
    }

    public function testValidateAcceptHeaderTypeWildcard(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML]);
        $request = new ServerRequest('GET', '/', ['Accept' => 'application/*']);
        $result  = $handler->testValidateAcceptHeader($request);
        // application/* matches the first allowed type
        self::assertSame('application/json', $result);
    }

    public function testValidateAcceptHeaderZeroQValueExcluded(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML]);
        $request = new ServerRequest('GET', '/', ['Accept' => 'application/json;q=0, application/xml']);
        $result  = $handler->testValidateAcceptHeader($request);
        self::assertSame('application/xml', $result);
    }

    // -- Content-Type validation --

    public function testValidateContentTypeEmpty(): void
    {
        $handler = $this->createHandler();
        $handler->testValidateRequestContentType(new ServerRequest('GET', '/'));
        $this->addToAssertionCount(1);
    }

    public function testValidateContentTypeValid(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedRequestContentTypes([RequestContentType::JSON]);
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'application/json']);
        $handler->testValidateRequestContentType($request);
        $this->addToAssertionCount(1);
    }

    public function testValidateContentTypeInvalid(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedRequestContentTypes([RequestContentType::JSON]);
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'text/plain']);
        $this->expectException(UnsupportedMediaTypeException::class);
        $handler->testValidateRequestContentType($request);
    }

    // -- Request param parsing --

    public function testGetRequestParamsFromQueryString(): void
    {
        $handler = $this->createHandler();
        $request = new ServerRequest('GET', '/?query=John3,16&version=NABRE');
        $params  = $handler->testGetRequestParams($request);
        self::assertSame('John3,16', $params['query']);
        self::assertSame('NABRE', $params['version']);
    }

    public function testGetRequestParamsFromJsonBody(): void
    {
        $handler = $this->createHandler();
        $body    = Stream::create('{"query":"John3,16","version":"CEI2008"}');
        $request = ( new ServerRequest('POST', '/') )
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
        $params  = $handler->testGetRequestParams($request);
        self::assertSame('John3,16', $params['query']);
        self::assertSame('CEI2008', $params['version']);
    }

    public function testGetRequestParamsMergesQueryAndBody(): void
    {
        $handler = $this->createHandler();
        $body    = Stream::create('{"version":"NABRE"}');
        $request = ( new ServerRequest('POST', '/?query=John3,16') )
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
        $params  = $handler->testGetRequestParams($request);
        self::assertSame('John3,16', $params['query']);
        self::assertSame('NABRE', $params['version']);
    }

    public function testGetRequestParamsMalformedJson(): void
    {
        $handler = $this->createHandler();
        $body    = Stream::create('{bad json');
        $request = ( new ServerRequest('POST', '/') )
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
        $this->expectException(BadRequestException::class);
        $handler->testGetRequestParams($request);
    }

    // -- Content type resolution --

    public function testResolveContentTypeFromReturnParam(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('GET', '/?return=xml'));
        self::assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));
    }

    public function testResolveContentTypeFromAcceptHeader(): void
    {
        $handler  = $this->createHandler();
        $request  = new ServerRequest('GET', '/', ['Accept' => 'application/xml']);
        $response = $handler->handle($request);
        self::assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));
    }

    public function testResolveContentTypeDefaultsToJson(): void
    {
        $handler  = $this->createHandler();
        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    // -- CORS preflight --

    public function testPreflightCorsResponse(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST]);

        $request = new ServerRequest('OPTIONS', '/', [
            'Origin'                        => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $response = new \Nyholm\Psr7\Response(200);
        $response = $handler->testHandlePreflightRequest($request, $response);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('GET', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testRegularOptionsResponse(): void
    {
        $handler = $this->createHandler();
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST]);

        $request  = new ServerRequest('OPTIONS', '/');
        $response = new \Nyholm\Psr7\Response(200);
        $response = $handler->testHandlePreflightRequest($request, $response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('GET', $response->getHeaderLine('Allow'));
    }

    // -- Fluent setters --

    public function testFluentSetters(): void
    {
        $handler = $this->createHandler();
        $result  = $handler
            ->setAllowedRequestMethods([RequestMethod::GET])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON])
            ->setAllowedRequestContentTypes([RequestContentType::JSON]);
        self::assertSame($handler, $result);
    }
}
