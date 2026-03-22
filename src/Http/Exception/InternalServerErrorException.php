<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

class InternalServerErrorException extends ApiException
{
    public function __construct(string $message = 'Internal Server Error', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::INTERNAL_SERVER_ERROR->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-500-internal-server-error',
            StatusCode::INTERNAL_SERVER_ERROR->reason(),
            $previous
        );
    }
}
