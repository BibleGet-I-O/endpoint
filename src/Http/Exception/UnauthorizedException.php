<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

use BibleGet\Api\Http\Enum\StatusCode;

/**
 * 401: no credential, or one that is unknown, revoked, expired or not yet approved.
 *
 * The problem+json carries a `developers` member pointing at the pages where
 * a project key is requested, and ErrorHandlingMiddleware adds the
 * WWW-Authenticate challenge RFC 9110 requires on a 401.
 */
class UnauthorizedException extends ApiException
{
    public const DEVELOPERS_URL   = 'https://www.bibleget.io/developers';
    public const WWW_AUTHENTICATE = 'ApiKey header="X-Api-Key"';

    public function __construct(
        string $message = 'A valid credential is required in the X-Api-Key header.',
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            StatusCode::UNAUTHORIZED->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-401-unauthorized',
            StatusCode::UNAUTHORIZED->reason(),
            $previous
        );
    }

    /**
     * @param bool $includeDebug Whether to include file, line, and stack trace.
     * @return array<string, mixed>
     */
    public function toArray(bool $includeDebug = false): array
    {
        return parent::toArray($includeDebug) + ['developers' => self::DEVELOPERS_URL];
    }
}
