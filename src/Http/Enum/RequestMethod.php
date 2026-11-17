<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Enum;

enum RequestMethod: string
{
    case GET     = 'GET';
    case POST    = 'POST';
    case PATCH   = 'PATCH';
    case DELETE  = 'DELETE';
    case OPTIONS = 'OPTIONS';
}
