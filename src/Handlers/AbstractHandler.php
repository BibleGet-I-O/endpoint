<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\StatusCode;
use BibleGet\Api\Http\Exception\MethodNotAllowedException;
use BibleGet\Api\Http\Exception\NotAcceptableException;
use BibleGet\Api\Http\Exception\UnsupportedMediaTypeException;
use BibleGet\Api\Http\CorsPolicy;
use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Api\Http\Exception\ValidationException;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class AbstractHandler implements RequestHandlerInterface
{
    /** @var RequestMethod[] */
    protected array $allowedRequestMethods;

    /** @var AcceptHeader[] */
    protected array $allowedAcceptHeaders;

    /** @var RequestContentType[] */
    protected array $allowedRequestContentTypes;

    /** @var string[] */
    protected array $requestPathParams;

    protected ResponseFactoryInterface $responseFactory;

    abstract public function handle(ServerRequestInterface $request): ResponseInterface;

    /**
     * @param string[] $requestPathParams
     */
    public function __construct(ResponseFactoryInterface $responseFactory, array $requestPathParams = [])
    {
        $this->responseFactory            = $responseFactory;
        $this->requestPathParams          = $requestPathParams;
        $this->allowedAcceptHeaders       = AcceptHeader::cases();
        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS];
        $this->allowedRequestContentTypes = RequestContentType::cases();
    }

    /**
     * @param RequestMethod[] $requestMethods
     */
    public function setAllowedRequestMethods(array $requestMethods): static
    {
        $this->allowedRequestMethods = $requestMethods;
        return $this;
    }

    /**
     * @param AcceptHeader[] $acceptHeaders
     */
    public function setAllowedAcceptHeaders(array $acceptHeaders): static
    {
        $this->allowedAcceptHeaders = $acceptHeaders;
        return $this;
    }

    /**
     * @param RequestContentType[] $requestContentTypes
     */
    public function setAllowedRequestContentTypes(array $requestContentTypes): static
    {
        $this->allowedRequestContentTypes = $requestContentTypes;
        return $this;
    }

    /**
     * Set CORS Access-Control-Allow-Origin header on the response.
     */
    protected function setAccessControlAllowOriginHeader(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return ( new CorsPolicy() )->applyHeaders($request, $response);
    }

    /**
     * Handle CORS preflight OPTIONS requests.
     */
    protected function handlePreflightRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $isCorsRequest = (
            $request->getMethod() === 'OPTIONS'
            && $request->getHeaderLine('Origin') !== ''
            && $request->getHeaderLine('Access-Control-Request-Method') !== ''
        );

        $response = $response->withStatus(StatusCode::OK->value, StatusCode::OK->reason());

        if ($isCorsRequest) {
            $response = $response->withStatus(StatusCode::NO_CONTENT->value, StatusCode::NO_CONTENT->reason());
            $response = $this->setAccessControlAllowOriginHeader($request, $response);

            $response = $response
                ->withAddedHeader('Vary', 'Access-Control-Request-Method')
                ->withAddedHeader('Vary', 'Access-Control-Request-Headers');

            $methodHeader = $request->getHeaderLine('Access-Control-Request-Method');
            if ($methodHeader !== '') {
                $response = $response->withHeader(
                    'Access-Control-Allow-Methods',
                    implode(',', array_column($this->allowedRequestMethods, 'value'))
                );
            }

            $headersHeader = $request->getHeaderLine('Access-Control-Request-Headers');
            if ($headersHeader !== '') {
                $allowed       = ['Accept', 'Accept-Language', 'Content-Type'];
                $requested     = array_values(array_filter(array_map('trim', explode(',', $headersHeader))));
                $canonicalByLc = array_combine(array_map('strtolower', $allowed), $allowed);
                $approved      = [];
                foreach ($requested as $header) {
                    $lc = strtolower($header);
                    if (isset($canonicalByLc[$lc])) {
                        $approved[$canonicalByLc[$lc]] = true;
                    }
                }
                if ($approved !== []) {
                    $response = $response->withHeader('Access-Control-Allow-Headers', implode(',', array_keys($approved)));
                }
            }

            $response = $response->withHeader('Access-Control-Max-Age', '86400');
        } else {
            $response = $response->withHeader(
                'Allow',
                implode(',', array_column($this->allowedRequestMethods, 'value'))
            );
        }

        return $response;
    }

    /**
     * @throws MethodNotAllowedException
     */
    protected function validateRequestMethod(ServerRequestInterface $request): void
    {
        if (!in_array($request->getMethod(), array_column($this->allowedRequestMethods, 'value'))) {
            throw new MethodNotAllowedException();
        }
    }

    /**
     * Validates the Accept header. Returns the best matching MIME type.
     *
     * @throws NotAcceptableException
     */
    protected function validateAcceptHeader(ServerRequestInterface $request): string
    {
        $acceptHeader = $request->getHeaderLine('Accept');
        if ($acceptHeader === '' || $acceptHeader === '*/*') {
            return $this->allowedAcceptHeaders[0]->value;
        }

        // Parse Accept header into (mediaRange, quality) pairs
        $acceptValues = array_map('trim', explode(',', $acceptHeader));
        /** @var array<int, array{mime: string, q: float, order: int}> $parsed */
        $parsed = [];
        foreach ($acceptValues as $order => $value) {
            $parts = array_map('trim', explode(';', $value));
            $mime  = $parts[0];
            $q     = 1.0;
            for ($i = 1; $i < count($parts); $i++) {
                if (str_starts_with($parts[$i], 'q=')) {
                    $q = (float) substr($parts[$i], 2);
                }
            }
            $parsed[] = ['mime' => $mime, 'q' => $q, 'order' => $order];
        }

        // For each allowed type, find the best matching quality score
        $bestMatch = null;
        $bestQ     = -1.0;
        $bestOrder = PHP_INT_MAX;

        foreach ($this->allowedAcceptHeaders as $allowed) {
            foreach ($parsed as $p) {
                $matches = false;
                if ($p['mime'] === $allowed->value) {
                    $matches = true;
                } elseif ($p['mime'] === '*/*') {
                    $matches = true;
                } else {
                    // Check type/* wildcard (e.g. application/*)
                    $slashPos = strpos($p['mime'], '/');
                    if ($slashPos !== false && substr($p['mime'], $slashPos + 1) === '*') {
                        $requestedType   = substr($p['mime'], 0, $slashPos);
                        $allowedSlashPos = strpos($allowed->value, '/');
                        $allowedType     = $allowedSlashPos !== false ? substr($allowed->value, 0, $allowedSlashPos) : '';
                        if ($requestedType === $allowedType) {
                            $matches = true;
                        }
                    }
                }
                // Treat text/html as matching AcceptHeader::HTML for browser friendliness
                if (!$matches && $p['mime'] === 'text/html' && $allowed === AcceptHeader::HTML) {
                    $matches = true;
                }

                if ($matches && $p['q'] > 0 && ( $p['q'] > $bestQ || ( $p['q'] === $bestQ && $p['order'] < $bestOrder ) )) {
                    $bestQ     = $p['q'];
                    $bestOrder = $p['order'];
                    $bestMatch = $allowed->value;
                }
            }
        }

        // Browser friendliness: if text/html was requested but HTML is not in allowed list, use default
        if ($bestMatch === null) {
            foreach ($parsed as $p) {
                if ($p['mime'] === 'text/html' && $p['q'] > 0) {
                    return $this->allowedAcceptHeaders[0]->value;
                }
            }
        }

        if ($bestMatch !== null) {
            return $bestMatch;
        }

        throw new NotAcceptableException();
    }

    /**
     * @throws UnsupportedMediaTypeException
     */
    protected function validateRequestContentType(ServerRequestInterface $request): void
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if ($contentType === '') {
            return; // No content type is fine for GET requests
        }

        // Strip charset and other parameters
        $mime = trim(explode(';', $contentType)[0]);

        if (!in_array($mime, array_column($this->allowedRequestContentTypes, 'value'))) {
            throw new UnsupportedMediaTypeException(
                'Allowed Content Types are ' . implode(' and ', array_column($this->allowedRequestContentTypes, 'value'))
                . ', but your Content Type was ' . $contentType
            );
        }
    }

    /**
     * Parse request parameters from query string and/or body (JSON or form data).
     * Merges body params over query params, matching current behavior.
     *
     * @return array<string, mixed>
     * @throws BadRequestException
     */
    protected function getRequestParams(ServerRequestInterface $request): array
    {
        /** @var array<string, mixed> $params */
        $params = $request->getQueryParams();

        $contentType = $request->getHeaderLine('Content-Type');
        $mime        = trim(explode(';', $contentType)[0]);

        if ($mime === 'application/json') {
            $body = (string) $request->getBody();
            if ($body !== '') {
                $decoded = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new BadRequestException(
                        'Malformed JSON data received in the request: ' . json_last_error_msg()
                    );
                }
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $params */
                    $params = array_merge($params, $decoded);
                }
            }
        } elseif ($mime === 'application/x-www-form-urlencoded') {
            $parsedBody = $request->getParsedBody();
            if (is_array($parsedBody)) {
                /** @var array<string, mixed> $params */
                $params = array_merge($params, $parsedBody);
            }
        }

        return $params;
    }

    /**
     * Determine the response content type from the `return` param or Accept header.
     *
     * @param array<string, mixed> $params
     */
    protected function resolveResponseContentType(ServerRequestInterface $request, array $params): string
    {
        // Check explicit `return` param first
        $returnParamRaw = $params['return'] ?? '';
        $returnParam    = is_string($returnParamRaw) ? $returnParamRaw : '';
        if ($returnParam !== '') {
            $map = [
                'json' => 'application/json',
                'xml'  => 'application/xml',
                'html' => 'text/html',
            ];
            if (isset($map[$returnParam])) {
                return $map[$returnParam];
            }
        }

        // Fall back to Accept header negotiation
        return $this->validateAcceptHeader($request);
    }

    /**
     * Create an initial Response with the correct Content-Type and CORS headers.
     */
    protected function initResponse(ServerRequestInterface $request, string $contentType): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(StatusCode::OK->value, StatusCode::OK->reason())
            ->withProtocolVersion($request->getProtocolVersion())
            ->withHeader('Content-Type', $contentType . '; charset=utf-8');

        return $this->setAccessControlAllowOriginHeader($request, $response);
    }

    /**
     * Add Cache-Control and ETag headers to the response.
     *
     * If the client sent an If-None-Match header that matches the ETag,
     * a 304 Not Modified response is returned instead.
     *
     * @param int $maxAge Cache max-age in seconds (default: 259200 = 3 days)
     */
    protected function withCacheHeaders(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $maxAge = 259200
    ): ResponseInterface {
        $body = (string) $response->getBody();
        $etag = '"' . md5($body) . '"';

        $response = $response
            ->withHeader('Cache-Control', 'must-revalidate, max-age=' . $maxAge)
            ->withHeader('ETag', $etag);

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && self::etagMatches($ifNoneMatch, $etag)) {
            return $response
                ->withStatus(StatusCode::NOT_MODIFIED->value, StatusCode::NOT_MODIFIED->reason())
                ->withBody(Stream::create(''));
        }

        return $response;
    }

    /**
     * Check whether an ETag is present in an If-None-Match header value.
     *
     * Handles the wildcard "*", comma-separated lists, and weak ETags (W/ prefix)
     * per RFC 9110 §13.1.2. If-None-Match uses weak comparison, so W/"abc" matches "abc".
     */
    private static function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '*') {
            return true;
        }

        $stripWeak     = static fn(string $e): string => str_starts_with($e, 'W/') ? substr($e, 2) : $e;
        $normalizedTag = $stripWeak($etag);

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if ($stripWeak(trim($candidate)) === $normalizedTag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write a JSON-encoded body to the response.
     *
     * @param mixed $data
     */
    protected function jsonResponse(ResponseInterface $response, mixed $data): ResponseInterface
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $response->withBody(Stream::create($encoded));
    }

    /**
     * Write an XML string body to the response.
     */
    protected function xmlResponse(ResponseInterface $response, string $xml): ResponseInterface
    {
        return $response->withBody(Stream::create($xml));
    }

    /**
     * Write an HTML string body to the response.
     */
    protected function htmlResponse(ResponseInterface $response, string $html): ResponseInterface
    {
        return $response->withBody(Stream::create($html));
    }
}
