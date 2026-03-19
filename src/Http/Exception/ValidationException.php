<?php

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

class ValidationException extends ApiException
{
    public function __construct(string $message = 'Validation failed', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::UNPROCESSABLE_CONTENT->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-422-unprocessable-content',
            StatusCode::UNPROCESSABLE_CONTENT->reason(),
            $previous
        );
    }
}
