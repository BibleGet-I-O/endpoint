<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The notes tables: note, note_anchor, note_ref (see the SQL file). Empty; the data comes from private data halves.
 */
final class Version064Notes extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'notes: note, note_anchor, note_ref';
    }

    protected function file(): string
    {
        return '064-notes.sql';
    }
}
