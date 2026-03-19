<?php

namespace BibleGet\Api\Http\Enum;

enum RequestMethod: string
{
    case GET     = 'GET';
    case POST    = 'POST';
    case OPTIONS = 'OPTIONS';
}
