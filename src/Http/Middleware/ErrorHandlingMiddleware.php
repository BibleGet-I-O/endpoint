<?php

namespace BibleGet\Api\Http\Middleware;

use BibleGet\Api\Http\Exception\ApiException;
use BibleGet\Api\Http\Exception\TooManyRequestsException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use Monolog\Logger;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ErrorHandlingMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private bool $debug;
    private Logger $errorLogger;
    private ?ServerRequestInterface $currentRequest = null;

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        bool $debug = false
    ) {
        $this->responseFactory = $responseFactory;
        $this->debug           = $debug;
        $this->errorLogger     = LoggerFactory::create('api-error', null, 30, $debug);

        register_shutdown_function([$this, 'handleShutdown']);
        set_exception_handler([$this, 'handleUncaughtException']);
        set_error_handler([$this, 'handlePhpWarning']);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->currentRequest = $request;

        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $this->logException($e);

            $status  = 500;
            $problem = [
                'type'   => 'about:blank',
                'title'  => 'Internal Server Error',
                'status' => $status,
                'detail' => $this->debug ? $e->getMessage() : 'An unexpected error occurred.',
            ];

            $retryAfter = null;
            if ($e instanceof ApiException) {
                $status  = $e->getStatus();
                $problem = $e->toArray($this->debug);

                if ($e instanceof TooManyRequestsException && $e->getRetryAfter() > 0) {
                    $retryAfter = $e->getRetryAfter();
                }
            } elseif ($this->debug) {
                $problem['file']  = $e->getFile();
                $problem['line']  = $e->getLine();
                $problem['trace'] = explode("\n", $e->getTraceAsString());
            }

            $response = $this->responseFactory->createResponse($status);

            if ($retryAfter !== null) {
                $response = $response->withHeader('Retry-After', (string) $retryAfter);
            }

            $responseBody = json_encode($problem, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (false === $responseBody) {
                $response->getBody()->write('{"type":"about:blank","title":"Internal Server Error","status":500}');
                $response = $response->withHeader('Content-Type', 'application/problem+json');
                $origin = $request->getHeaderLine('Origin');
                if ($origin !== '') {
                    $response = $response
                        ->withHeader('Access-Control-Allow-Origin', $origin)
                        ->withHeader('Access-Control-Allow-Credentials', 'true');
                } else {
                    $response = $response->withHeader('Access-Control-Allow-Origin', '*');
                }
                return $response;
            }

            $response->getBody()->write($responseBody);

            $response = $response->withHeader('Content-Type', 'application/problem+json');

            // CORS: reflect Origin if present, otherwise wildcard
            $origin = $request->getHeaderLine('Origin');
            if ($origin !== '') {
                $response = $response
                    ->withHeader('Access-Control-Allow-Origin', $origin)
                    ->withHeader('Access-Control-Allow-Credentials', 'true');
            } else {
                $response = $response->withHeader('Access-Control-Allow-Origin', '*');
            }

            return $response;
        }
    }

    /**
     * @return bool
     */
    public function handlePhpWarning(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        switch ($errno) {
            case E_WARNING:
            case E_NOTICE:
            case E_USER_WARNING:
            case E_USER_NOTICE:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
            case E_RECOVERABLE_ERROR:
                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);

            default:
                return false;
        }
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            $this->logException($exception, 'critical');
        }
    }

    public function handleUncaughtException(\Throwable $e): void
    {
        $this->logException($e, 'critical');
        exit(1);
    }

    private function logException(\Throwable $e, string $severity = 'error'): void
    {
        $method = $this->currentRequest?->getMethod() ?? 'N/A';
        $uri    = $this->currentRequest?->getUri()?->__toString() ?? 'N/A';

        $fullMessage = sprintf(
            "[%s %s] %s in %s:%d\nStack trace:\n%s",
            $method,
            $uri,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        $this->errorLogger->{$severity}($fullMessage, [
            'request_id' => $this->currentRequest?->getAttribute('request_id'),
            'exception'  => $e,
        ]);
    }
}
