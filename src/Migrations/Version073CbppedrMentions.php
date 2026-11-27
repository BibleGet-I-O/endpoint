<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * Where CBPPEDR entries are named in the text, and their cards (see the SQL file).
 */
final class Version073CbppedrMentions extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'bpe_*: CBPPEDR mentions, details and verse counts';
    }

    protected function file(): string
    {
        return '073-cbppedr-mentions.sql';
    }
}
