<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * A note's citation may point into another edition (note_ref.edition; NULL = the note's own).
 */
final class Version066NoteRefEdition extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'note_ref.edition: citations into another edition';
    }

    protected function file(): string
    {
        return '066-note-ref-edition.sql';
    }
}
