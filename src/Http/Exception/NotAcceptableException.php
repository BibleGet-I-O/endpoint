<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

class NotAcceptableException extends ApiException
{
    public function __construct(string $message = 'Not Acceptable', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::NOT_ACCEPTABLE->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-406-not-acceptable',
            StatusCode::NOT_ACCEPTABLE->reason(),
            $previous
        );
    }
}
