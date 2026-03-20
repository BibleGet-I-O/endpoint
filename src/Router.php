<?php

declare(strict_types=1);

namespace BibleGet\Api;

use BibleGet\Api\Handlers\QuoteHandler;
use BibleGet\Api\Handlers\MetadataHandler;
use BibleGet\Api\Handlers\SearchHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Enum\StatusCode;
use BibleGet\Api\Http\Middleware\ErrorHandlingMiddleware;
use BibleGet\Api\Http\Middleware\LoggingMiddleware;
use BibleGet\Api\Http\Server\MiddlewarePipeline;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Router
{
    private RequestHandlerInterface $handler;
    private Psr17Factory $psr17Factory;
    private ServerRequestInterface $request;
    private string $requestId;
    private ResponseInterface $response;
    private static bool $debug;

    public function __construct()
    {
        if (!isset(self::$debug)) {
            self::$debug = self::isLocalhost();
        }

        $this->psr17Factory = new Psr17Factory();
        $this->request      = $this->retrieveRequest();

        try {
            $this->requestId = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $this->requestId = uniqid('bg');
        }

        $this->request = $this->request->withAttribute('request_id', $this->requestId);
    }

    /**
     * Route the incoming request and emit the response.
     *
     * @return never
     */
    public function route(): never
    {
        $path      = $this->request->getUri()->getPath();
        $pathParts = array_values(array_filter(explode('/', $path)));

        // Strip known base path prefix (e.g. "v3") if present
        if (!empty($pathParts) && $pathParts[0] === 'v3') {
            array_shift($pathParts);
        }

        $route            = array_shift($pathParts) ?? '';
        $requestPathParts = $pathParts;

        switch ($route) {
            case '':
            case 'quote':
                $quoteHandler = new QuoteHandler($requestPathParts);
                $quoteHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::OPTIONS,
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::FORMDATA,
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::XML,
                    AcceptHeader::HTML,
                ]);
                $this->handler = $quoteHandler;
                break;

            case 'metadata':
                $metadataHandler = new MetadataHandler($requestPathParts);
                $metadataHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::OPTIONS,
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::FORMDATA,
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::XML,
                    AcceptHeader::HTML,
                ]);
                $this->handler = $metadataHandler;
                break;

            case 'search':
                $searchHandler = new SearchHandler($requestPathParts);
                $searchHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::OPTIONS,
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::FORMDATA,
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::XML,
                    AcceptHeader::HTML,
                ]);
                $this->handler = $searchHandler;
                break;

            default:
                $this->response = new Response(
                    StatusCode::NOT_FOUND->value,
                    [],
                    null,
                    $this->request->getProtocolVersion(),
                    StatusCode::NOT_FOUND->reason()
                );
                $this->emitResponse();
        }

        $pipeline = new MiddlewarePipeline($this->handler);
        $pipeline->pipe(new ErrorHandlingMiddleware($this->psr17Factory, self::$debug));
        $pipeline->pipe(new LoggingMiddleware(self::$debug));

        $this->response = $pipeline->handle($this->request)
            ->withHeader('X-Request-Id', $this->requestId);
        $this->emitResponse();
    }

    /**
     * @param array<string, mixed>|null $serverParams  Override for testing; defaults to $_SERVER.
     */
    public static function isLocalhost(?array $serverParams = null): bool
    {
        $server = $serverParams ?? $_SERVER;
        $serverAddress      = (string) ($server['SERVER_ADDR'] ?? '');
        $remoteAddress      = (string) ($server['REMOTE_ADDR'] ?? '');
        $serverName         = (string) ($server['SERVER_NAME'] ?? '');
        $localhostAddresses = ['127.0.0.1', '::1', '0.0.0.0'];
        $localhostNames     = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        return in_array($serverAddress, $localhostAddresses)
            || in_array($remoteAddress, $localhostAddresses)
            || in_array($serverName, $localhostNames);
    }

    private function retrieveRequest(): ServerRequestInterface
    {
        $creator = new ServerRequestCreator(
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory
        );
        return $creator->fromGlobals();
    }

    private function emitResponse(): never
    {
        $sapiEmitter = new SapiEmitter();
        $sapiEmitter->emit($this->response);
        exit;
    }
}
