<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

class ServiceUnavailableException extends ApiException
{
    public function __construct(string $message = 'Service Unavailable', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::SERVICE_UNAVAILABLE->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-503-service-unavailable',
            StatusCode::SERVICE_UNAVAILABLE->reason(),
            $previous
        );
    }
}
