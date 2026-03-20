<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Resource Not Found', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::NOT_FOUND->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-404-not-found',
            StatusCode::NOT_FOUND->reason(),
            $previous
        );
    }
}
