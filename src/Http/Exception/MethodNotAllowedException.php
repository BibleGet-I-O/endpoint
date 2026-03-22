<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

class MethodNotAllowedException extends ApiException
{
    public function __construct(string $message = 'Method Not Allowed', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::METHOD_NOT_ALLOWED->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-405-method-not-allowed',
            StatusCode::METHOD_NOT_ALLOWED->reason(),
            $previous
        );
    }
}
