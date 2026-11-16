<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The bibleget_<cfg> text search configurations of migration 043 (#196) fold
 * accents and ligatures for every token type that can carry them, and keep
 * each token type's own dictionaries behind unaccent.
 */
final class UnaccentSearchConfigTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function pairs(): array
    {
        return [
            'word'         => ['bibleget_simple', 'Israël', 'Israel'],
            'ligature'     => ['bibleget_simple', 'cælum', 'caelum'],
            'numword'      => ['bibleget_simple', 'Israël2', 'Israel2'],
            'numhword'     => ['bibleget_simple', 'Israël2-cælum', 'Israel2-caelum'],
            'english stem' => ['bibleget_english', 'créated', 'create'],
            'english num'  => ['bibleget_english', 'Noël2', 'Noel2'],
        ];
    }

    #[DataProvider('pairs')]
    public function testTextAndKeywordFoldAlike(string $config, string $text, string $keyword): void
    {
        $pdo  = $this->getConnection();
        $stmt = $pdo->prepare('SELECT to_tsvector(?::regconfig, ?) @@ plainto_tsquery(?::regconfig, ?)');
        $stmt->execute([$config, $text, $config, $keyword]);
        self::assertTrue((bool) $stmt->fetchColumn(), "$config: '$keyword' should match '$text'");
    }
}
