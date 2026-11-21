<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Migration 058: each edition's words (lowercased, as printed) with their folded form and the number of verses that
 * contain them, for /search/suggest. Short words and the edition language's stop words are left out, so every word
 * listed is one keyword search accepts.
 */
final class SearchVocabularyTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/058-search-vocabulary.sql';

    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnection()->exec((string) file_get_contents(self::MIGRATION));
    }

    private function freq(string $sigla, string $word): ?int
    {
        $stmt = $this->getConnection()->prepare('SELECT freq FROM search_vocabulary WHERE sigla = ? AND word = ?');
        $stmt->execute([$sigla, $word]);
        $freq = $stmt->fetchColumn();
        return $freq === false ? null : (int) $freq;
    }

    private function versesContaining(string $sigla, string $word): int
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT count(*) FROM "' . $sigla . '" WHERE ? = ANY (regexp_split_to_array(lower(text_plain), \'[^[:alpha:]]+\'))'
        );
        $stmt->execute([$word]);
        return (int) $stmt->fetchColumn();
    }

    public function testEveryEditionHasItsWords(): void
    {
        self::assertSame($this->versesContaining('TEST1', 'light'), $this->freq('TEST1', 'light'));
        self::assertGreaterThan(0, $this->freq('TEST1', 'light'));
        self::assertGreaterThan(0, $this->freq('VGCL', 'israël'));
    }

    public function testStopWordsAndShortWordsAreLeftOut(): void
    {
        self::assertNull($this->freq('TEST1', 'there'));
        self::assertNull($this->freq('TEST1', 'and'));
        self::assertNull($this->freq('TEST1', 'god'));
    }

    public function testFoldedFormDropsAccents(): void
    {
        $stmt = $this->getConnection()->query("SELECT folded FROM search_vocabulary WHERE sigla = 'VGCL' AND word = 'israël'");
        self::assertNotFalse($stmt);
        self::assertSame('israel', $stmt->fetchColumn());
    }

    public function testRefreshIsIdempotent(): void
    {
        $pdo   = $this->getConnection();
        $first = $pdo->query("SELECT refresh_search_vocabulary('TEST1')");
        self::assertNotFalse($first);
        $n     = (int) $first->fetchColumn();
        $again = $pdo->query("SELECT refresh_search_vocabulary('TEST1')");
        self::assertNotFalse($again);
        self::assertSame($n, (int) $again->fetchColumn());
        $count = $pdo->query("SELECT count(*) FROM search_vocabulary WHERE sigla = 'TEST1'");
        self::assertNotFalse($count);
        self::assertSame($n, (int) $count->fetchColumn());
    }
}
