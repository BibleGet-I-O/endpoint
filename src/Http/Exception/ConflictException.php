<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

/** 409: the request conflicts with the resource's current state (e.g. keys for an unapproved application). */
class ConflictException extends ApiException
{
    public function __construct(string $message = 'Conflict', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::CONFLICT->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-409-conflict',
            StatusCode::CONFLICT->reason(),
            $previous
        );
    }
}
