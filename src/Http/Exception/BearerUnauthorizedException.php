<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

/**
 * 401 on the developer and admin routes, which take a Zitadel access token in
 * `Authorization: Bearer` rather than a credential in X-Api-Key.
 */
class BearerUnauthorizedException extends UnauthorizedException
{
    public const string WWW_AUTHENTICATE = 'Bearer realm="bibleget"';

    public function __construct(
        string $message = 'A valid access token is required in the Authorization: Bearer header.',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $previous);
    }
}
