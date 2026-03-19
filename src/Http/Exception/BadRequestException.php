<?php

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

class BadRequestException extends ApiException
{
    public function __construct(string $message = 'Bad Request', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::BAD_REQUEST->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-400-bad-request',
            StatusCode::BAD_REQUEST->reason(),
            $previous
        );
    }
}
