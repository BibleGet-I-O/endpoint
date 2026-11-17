<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

use BibleGet\Api\Http\Exception\ForbiddenException;

/**
 * Thrown when a revoked installation tries to register again (maps to 403).
 */
class InstallationRevokedException extends ForbiddenException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This installation has been revoked', $previous);
    }
}
