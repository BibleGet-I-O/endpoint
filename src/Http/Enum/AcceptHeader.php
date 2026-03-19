<?php

namespace BibleGet\Api\Http\Enum;

enum AcceptHeader: string
{
    case JSON = 'application/json';
    case XML  = 'application/xml';
    case HTML = 'text/html';
}
