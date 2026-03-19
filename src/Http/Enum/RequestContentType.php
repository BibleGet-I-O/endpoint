<?php

namespace BibleGet\Api\Http\Enum;

enum RequestContentType: string
{
    case JSON     = 'application/json';
    case FORMDATA = 'application/x-www-form-urlencoded';
}
