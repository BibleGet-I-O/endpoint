<?php

namespace BibleGet\Api\Http\Middleware;

use BibleGet\Api\Http\Logs\LoggerFactory;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    private Logger $logger;

    public function __construct(bool $debug = false)
    {
        $this->logger = LoggerFactory::create('api', null, 30, $debug);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId      = $request->getAttribute('request_id');
        $reqContentType = $request->getHeaderLine('Content-Type');
        $safeReqBody    = str_starts_with($reqContentType, 'application/json') || str_starts_with($reqContentType, 'text/')
            ? self::readBody($request->getBody())
            : '[body omitted]';

        $this->logger->debug('Incoming request', [
            'request_id'   => $requestId,
            'method'       => $request->getMethod(),
            'uri'          => (string) $request->getUri(),
            'content_type' => $reqContentType,
            'request_body' => $safeReqBody,
        ]);

        $response = $handler->handle($request);

        $resContentType = $response->getHeaderLine('Content-Type');
        $responseBody   = $response->getBody();
        if (!$responseBody->isSeekable()) {
            $safeResBody = '[non-seekable body omitted]';
        } elseif (str_starts_with($resContentType, 'application/json') || str_starts_with($resContentType, 'application/problem+json') || str_starts_with($resContentType, 'text/')) {
            $safeResBody = self::readBody($responseBody);
        } else {
            $safeResBody = '[body omitted]';
        }

        $this->logger->debug('Outgoing response', [
            'request_id'    => $requestId,
            'status'        => $response->getStatusCode(),
            'content_type'  => $resContentType,
            'response_body' => $safeResBody,
        ]);

        return $response;
    }

    private static function readBody(StreamInterface $body): string
    {
        if ($body->isSeekable()) {
            $position = $body->tell();
            $body->rewind();
            $contents = (string) $body;
            $body->seek($position);
        } else {
            $contents = $body->getContents();
        }

        return $contents;
    }
}
