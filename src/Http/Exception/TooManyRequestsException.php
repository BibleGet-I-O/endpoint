<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

class TooManyRequestsException extends ApiException
{
    private int $retryAfter;
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers Extra response headers (e.g. X-RateLimit-*).
     */
    public function __construct(
        string $message = 'Too many requests. Please try again later.',
        int $retryAfter = 0,
        ?\Throwable $previous = null,
        array $headers = []
    ) {
        parent::__construct(
            $message,
            StatusCode::TOO_MANY_REQUESTS->value,
            'https://datatracker.ietf.org/doc/html/rfc6585#section-4',
            StatusCode::TOO_MANY_REQUESTS->reason(),
            $previous
        );

        $this->retryAfter = max(0, $retryAfter);
        $this->headers    = $headers;
    }

    /**
     * Response headers to send with the 429, besides Retry-After.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * @param bool $includeDebug Whether to include file, line, and stack trace.
     * @return array<string, mixed>
     */
    public function toArray(bool $includeDebug = false): array
    {
        $data = parent::toArray($includeDebug);

        if ($this->retryAfter > 0) {
            $data['retryAfter'] = $this->retryAfter;
        }

        return $data;
    }
}
