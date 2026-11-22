<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * Book names the SeminaVerbi pilot found missing: Atos and Atos dos Apóstolos (pt), Actes des Apôtres (fr), 1/2 Thes (en);
 * none is held by another book in any language (see the SQL file).
 */
final class Version061MoreBookNames extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'biblebooks_fullname/abbr: Atos, Atos dos Apóstolos (pt), Actes des Apôtres (fr), 1Thes and 2Thes (en)';
    }

    protected function file(): string
    {
        return '061-more-book-names.sql';
    }
}
