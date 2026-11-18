<?php

declare(strict_types=1);

namespace BibleGet\Api\Auth;

enum CredentialType: string
{
    case Project      = 'project';
    case Client       = 'client';
    case Installation = 'installation';

    public function prefix(): string
    {
        return match ($this) {
            self::Project      => 'bibleget_',
            self::Client       => 'bgclient_',
            self::Installation => 'bginst_',
        };
    }
}
