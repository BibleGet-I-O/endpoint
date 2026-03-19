<?php

namespace BibleGet\Api\Http\Enum;

enum StatusCode: int
{
    case OK                     = 200;
    case NO_CONTENT             = 204;
    case BAD_REQUEST            = 400;
    case NOT_FOUND              = 404;
    case METHOD_NOT_ALLOWED     = 405;
    case NOT_ACCEPTABLE         = 406;
    case UNSUPPORTED_MEDIA_TYPE = 415;
    case UNPROCESSABLE_CONTENT  = 422;
    case TOO_MANY_REQUESTS      = 429;
    case INTERNAL_SERVER_ERROR  = 500;

    public function reason(): string
    {
        return match ($this) {
            self::OK                     => 'OK',
            self::NO_CONTENT             => 'No Content',
            self::BAD_REQUEST            => 'Bad Request',
            self::NOT_FOUND              => 'Not Found',
            self::METHOD_NOT_ALLOWED     => 'Method Not Allowed',
            self::NOT_ACCEPTABLE         => 'Not Acceptable',
            self::UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type',
            self::UNPROCESSABLE_CONTENT  => 'Unprocessable Content',
            self::TOO_MANY_REQUESTS      => 'Too Many Requests',
            self::INTERNAL_SERVER_ERROR  => 'Internal Server Error',
        };
    }
}
