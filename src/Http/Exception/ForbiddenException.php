<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

class ForbiddenException extends ApiException
{
    public function __construct(string $message = 'Forbidden', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::FORBIDDEN->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-403-forbidden',
            StatusCode::FORBIDDEN->reason(),
            $previous
        );
    }
}
