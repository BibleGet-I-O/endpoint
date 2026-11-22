<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * Each edition's words with their folded form and how many verses contain them, for /search/suggest; filled
 * by refresh_search_vocabulary(sigla), which later imports and text corrections call too (see the SQL file).
 */
final class Version058SearchVocabulary extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'search_vocabulary: each edition\'s words for keyword search suggestions';
    }

    protected function file(): string
    {
        return '058-search-vocabulary.sql';
    }
}
