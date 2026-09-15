<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use BibleGet\Api\Pipeline\Tokenizer\TokenType;
use BibleGet\Api\Util\StringUtils;
use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Data-integrity guard for biblebooks_fullname / biblebooks_abbr.
 *
 * Every stored book name and abbreviation, in every language, must survive
 * the trip a user query takes — whitespace stripped, proper-cased, tokenized,
 * looked up — and land back on the book it is stored under. This catches, in
 * one place, the whole class of "stored value that cannot be parsed back":
 * malformed ` | ` separators (#142), leading digits the tokenizer rejects
 * (#145), and abbreviations shadowed by a lower-numbered book in another
 * language (#146).
 *
 * Only the exact lookup pass is exercised, never the diacritic-folded
 * fallback (#147), so a stored value must resolve on its own merits.
 */
final class BibleBooksIntegrityTest extends DatabaseTestCase
{
    private const TABLES = ['biblebooks_fullname', 'biblebooks_abbr'];

    /**
     * Every non-empty cell as [table, language, book, value].
     *
     * @return list<array{string, string, int, string}>
     */
    private function allBibleBookCells(): array
    {
        $cells = [];
        foreach (self::TABLES as $table) {
            $stmt = $this->getConnection()->query('SELECT * FROM ' . $table . ' ORDER BY "BOOK"');
            self::assertNotFalse($stmt);
            foreach ($stmt->fetchAll() as $row) {
                self::assertIsArray($row);
                $book = (int) $row['BOOK'];
                foreach ($row as $language => $value) {
                    if ($language === 'BOOK' || !is_string($value) || $value === '') {
                        continue;
                    }
                    $cells[] = [$table, $language, $book, $value];
                }
            }
        }
        self::assertNotEmpty($cells, 'book tables are empty — is tests/fixtures/biblebooks.sql loaded?');
        return $cells;
    }

    /**
     * Known exception: Korean and Japanese number some books *after* the
     * name (요한1서, マカバイ記1), which the grammar does not support yet.
     * Tracked in #150 — remove this once that lands.
     */
    private function isPostfixNumbered(string $variant): bool
    {
        return (bool) preg_match('/\p{L}\s*\d+(서)?$/u', $variant);
    }

    private function contextWithRealBibleBooks(): QuoteContext
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();
        self::assertCount(73, $ctx->BIBLEBOOKS);
        return $ctx;
    }

    /**
     * What the pipeline hands the validator for a query beginning with $variant:
     * queryStrClean() strips whitespace and proper-cases, the tokenizer splits
     * off an optional numbered-book prefix, and AstValidator re-joins the two.
     */
    private function bookTokenFor(string $variant): ?string
    {
        $normalized = StringUtils::normalizeBibleBook($variant);
        $tokens     = ( new ReferenceTokenizer() )->tokenize($normalized . '1,1');
        $prefix     = '';
        foreach ($tokens as $token) {
            if ($token->type === TokenType::BOOK_NUMERIC_PREFIX) {
                $prefix = $token->value;
            } elseif ($token->type === TokenType::BOOK_NAME) {
                $book = $prefix . $token->value;
                // A book token that stops short (at a hyphen, an inner digit, …)
                // leaves the rest of the name to be misread as chapter/verse.
                return $book === $normalized ? $book : null;
            } else {
                return null;
            }
        }
        return null;
    }

    public function testBothTablesHaveTheSame73Books(): void
    {
        foreach (self::TABLES as $table) {
            $stmt = $this->getConnection()->query('SELECT "BOOK" FROM ' . $table . ' ORDER BY "BOOK"');
            self::assertNotFalse($stmt);
            self::assertSame(range(1, 73), array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), $table);
        }
    }

    public function testBothTablesExposeTheSameLanguages(): void
    {
        $languages = [];
        foreach (self::TABLES as $table) {
            $stmt = $this->getConnection()->query('SELECT * FROM ' . $table . ' LIMIT 1');
            self::assertNotFalse($stmt);
            $row = $stmt->fetch();
            self::assertIsArray($row);
            $cols = array_keys($row);
            sort($cols);
            $languages[$table] = $cols;
        }
        self::assertSame($languages['biblebooks_fullname'], $languages['biblebooks_abbr']);
    }

    /**
     * Both loaders split alternates with explode(' | ', ...): the spaces are
     * part of the delimiter, so 'A|B' or 'A |B' silently survives as one
     * dead token.
     */
    public function testAlternateSeparatorIsAlwaysACushionedPipe(): void
    {
        foreach ($this->allBibleBookCells() as [$table, $language, $book, $value]) {
            if (!str_contains($value, '|')) {
                continue;
            }
            self::assertSame(
                substr_count($value, '|'),
                substr_count($value, ' | '),
                "{$table}.{$language}[{$book}] uses a pipe that is not the ' | ' delimiter: '$value'"
            );
            foreach (explode(' | ', $value) as $part) {
                self::assertNotSame('', trim($part), "{$table}.{$language}[{$book}] has an empty alternate: '$value'");
                self::assertSame($part, trim($part), "{$table}.{$language}[{$book}] has an alternate with stray whitespace: '$value'");
            }
        }
    }

    /**
     * Every stored variant must tokenize as a book indicator (#145).
     */
    public function testEveryStoredVariantTokenizesAsABookIndicator(): void
    {
        $failures = [];
        foreach ($this->allBibleBookCells() as [$table, $language, $book, $value]) {
            foreach (explode(' | ', $value) as $variant) {
                if ($this->isPostfixNumbered($variant)) {
                    continue;
                }
                if ($this->bookTokenFor($variant) === null) {
                    $failures[] = "{$table}.{$language}[{$book}]: '$variant' does not tokenize as a book indicator";
                }
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * Every stored variant must resolve — through the exact pass — to the book
     * it is stored under. A token shared by two languages for the *same* book
     * is fine and common ('Job', 'Lev', 'Ps'); a token that resolves to a
     * *different* book is shadowed and unreachable (#142, #146).
     */
    public function testEveryStoredVariantResolvesToItsOwnBook(): void
    {
        $ctx      = $this->contextWithRealBibleBooks();
        $failures = [];
        foreach ($this->allBibleBookCells() as [$table, $language, $book, $value]) {
            foreach (explode(' | ', $value) as $variant) {
                if ($this->isPostfixNumbered($variant)) {
                    continue;
                }
                $token = $this->bookTokenFor($variant);
                if ($token === null) {
                    continue; // reported by the tokenization test
                }
                $resolved = QuoteContext::idxOf($token, $ctx->BIBLEBOOKS);
                if ($resolved === false) {
                    $failures[] = "{$table}.{$language}[{$book}]: '$variant' does not resolve to any book";
                } elseif ($resolved !== $book - 1) {
                    $failures[] = "{$table}.{$language}[{$book}]: '$variant' resolves to book " . ( $resolved + 1 ) . ' instead';
                }
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }
}
