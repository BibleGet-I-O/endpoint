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
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
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
    public static string $apiBase = '/';
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

        self::resolveBasePath();

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
        $path             = $this->request->getUri()->getPath();
        $pathParams       = str_starts_with($path, self::$apiBase)
            ? substr($path, strlen(self::$apiBase))
            : $path;
        $pathParams       = rtrim($pathParams, '/');
        $requestPathParts = array_values(array_filter(explode('/', $pathParams)));
        $route            = array_shift($requestPathParts) ?? '';

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
                $protocolVersion = $this->request->getProtocolVersion();
                $this->handler   = new class ($protocolVersion) implements RequestHandlerInterface {
                    public function __construct(private readonly string $protocolVersion)
                    {
                    }

                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new Response(
                            StatusCode::NOT_FOUND->value,
                            [],
                            null,
                            $this->protocolVersion,
                            StatusCode::NOT_FOUND->reason()
                        );
                    }
                };
                break;
        }

        $pipeline = new MiddlewarePipeline($this->handler);
        $pipeline->pipe(new ErrorHandlingMiddleware($this->psr17Factory, self::$debug));
        $pipeline->pipe(new LoggingMiddleware(self::$debug));

        $this->response = $pipeline->handle($this->request)
            ->withHeader('X-Request-Id', $this->requestId);
        $this->emitResponse();
    }

    /**
     * Resolve the API base path from the environment.
     *
     * In production, API_BASE_PATH must be set in the environment.
     * In localhost/development, it defaults to '/'.
     */
    private static function resolveBasePath(): void
    {
        if (
            false === self::isLocalhost()
            && (
                false === isset($_ENV['API_BASE_PATH'])
                || false === is_string($_ENV['API_BASE_PATH'])
                || empty($_ENV['API_BASE_PATH'])
            )
        ) {
            throw new ServiceUnavailableException('The API_BASE_PATH environment variable must be set in production environments.');
        }

        if (isset($_ENV['API_BASE_PATH']) && is_string($_ENV['API_BASE_PATH']) && !empty($_ENV['API_BASE_PATH'])) {
            $apiBasePath = trim($_ENV['API_BASE_PATH']);
        } else {
            $apiBasePath = '/';
        }

        // Normalize: ensure leading slash, strip duplicates
        $apiBasePath = '/' . trim($apiBasePath, '/');

        // Ensure trailing slash (but avoid double-slash for root)
        if ($apiBasePath !== '/' && substr($apiBasePath, -1) !== '/') {
            $apiBasePath .= '/';
        }

        self::$apiBase = $apiBasePath;
    }

    /**
     * @param array<string, mixed>|null $serverParams  Override for testing; defaults to $_SERVER.
     */
    public static function isLocalhost(?array $serverParams = null): bool
    {
        $server             = $serverParams ?? $_SERVER;
        $rawAddr            = $server['SERVER_ADDR'] ?? '';
        $rawRemote          = $server['REMOTE_ADDR'] ?? '';
        $rawName            = $server['SERVER_NAME'] ?? '';
        $serverAddress      = is_string($rawAddr) ? $rawAddr : '';
        $remoteAddress      = is_string($rawRemote) ? $rawRemote : '';
        $serverName         = is_string($rawName) ? $rawName : '';
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
