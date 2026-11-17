<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Middleware;

use BibleGet\Api\Http\Logs\LoggerFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs request and response bodies in debug mode. Credentials never reach the
 * log: no headers are logged (so neither Authorization nor X-Api-Key), the
 * JSON members `key`, `token` and `contact_email` are redacted at any depth,
 * and any string shaped like a BibleGet credential is masked wherever it
 * appears in a logged body or URI.
 */
class LoggingMiddleware implements MiddlewareInterface
{
    private const REDACTED        = '[redacted]';
    private const REDACTED_FIELDS = ['key', 'token', 'contact_email'];
    private const CREDENTIAL_RE   = '/\b(?:bibleget|bgclient|bginst)_(?:live|test)_[0-9a-f]{32}\b/i';

    private LoggerInterface $logger;
    private bool $debug;

    public function __construct(bool $debug = false, ?LoggerInterface $logger = null)
    {
        $this->debug  = $debug;
        $this->logger = $logger ?? LoggerFactory::create('api', null, 30, $debug);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->debug) {
            $requestId      = $request->getAttribute('request_id');
            $reqContentType = $request->getHeaderLine('Content-Type');
            $reqBody        = $request->getBody();
            if (!$reqBody->isSeekable()) {
                $safeReqBody = '[non-seekable body omitted]';
            } elseif (str_starts_with($reqContentType, 'application/json') || str_starts_with($reqContentType, 'text/')) {
                $safeReqBody = self::redact(self::readBody($reqBody), $reqContentType);
            } else {
                $safeReqBody = '[body omitted]';
            }

            $this->logger->debug('Incoming request', [
                'request_id'   => $requestId,
                'method'       => $request->getMethod(),
                'uri'          => self::maskCredentials((string) $request->getUri()),
                'content_type' => $reqContentType,
                'request_body' => $safeReqBody,
            ]);
        }

        $response = $handler->handle($request);

        if ($this->debug) {
            $requestId      = $requestId ?? $request->getAttribute('request_id');
            $resContentType = $response->getHeaderLine('Content-Type');
            $responseBody   = $response->getBody();
            if (!$responseBody->isSeekable()) {
                $safeResBody = '[non-seekable body omitted]';
            } elseif (str_starts_with($resContentType, 'application/json') || str_starts_with($resContentType, 'application/problem+json') || str_starts_with($resContentType, 'text/')) {
                $safeResBody = self::redact(self::readBody($responseBody), $resContentType);
            } else {
                $safeResBody = '[body omitted]';
            }

            $this->logger->debug('Outgoing response', [
                'request_id'    => $requestId,
                'status'        => $response->getStatusCode(),
                'content_type'  => $resContentType,
                'response_body' => $safeResBody,
            ]);
        }

        return $response;
    }

    /**
     * Read a seekable stream body without consuming it.
     * Callers must check isSeekable() before calling this method.
     */
    private static function readBody(StreamInterface $body): string
    {
        $position = $body->tell();
        $body->rewind();
        $contents = (string) $body;
        $body->seek($position);

        return $contents;
    }

    /**
     * A body as it may be logged: JSON with the sensitive members replaced,
     * any other text with credential-shaped strings masked.
     */
    private static function redact(string $body, string $contentType): string
    {
        if (str_contains($contentType, 'json') && $body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return '[unparseable JSON body omitted]';
            }
            $body = json_encode(self::redactValue($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
        }
        return self::maskCredentials($body);
    }

    private static function redactValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $name => $member) {
            $value[$name] = is_string($name) && in_array(strtolower($name), self::REDACTED_FIELDS, true)
                ? self::REDACTED
                : self::redactValue($member);
        }
        return $value;
    }

    private static function maskCredentials(string $text): string
    {
        return preg_replace(self::CREDENTIAL_RE, self::REDACTED, $text) ?? self::REDACTED;
    }
}
