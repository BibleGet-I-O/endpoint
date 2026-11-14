<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * text_plain derivation and the title_position guard (#123, migration 019).
 *
 * `text` carries formatting markup for rendering; `text_plain` is derived
 * from it by bibleget_plain_text() in the text_hash trigger, and is what
 * search and the embedding pipeline read. title_position places the verse's
 * headings inside the verse and must sit on a token boundary.
 */
class TextPlainTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->getConnection()->query('SELECT text_plain, title_position FROM "TEST1" LIMIT 1');
        } catch (\PDOException $e) {
            self::markTestSkipped('text_plain/title_position not in the test schema: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizerCases(): array
    {
        return [
            'inline tag joins its text'      => ['the L<sm>ORD</sm> God', 'the LORD God'],
            'italics removed without space'  => ['<i>Sela</i>.', 'Sela.'],
            'line tags become spaces'        => ["<pof>first line,</pof>\n<poi>second line.</poi>", 'first line, second line.'],
            'line break becomes a space'     => ['one<br />two<br/>three', 'one two three'],
            'whitespace collapsed + trimmed' => ["  a \t b\n\nc  ", 'a b c'],
            'unknown tags are left alone'    => ['<Aleph> Beati immaculati', '<Aleph> Beati immaculati'],
            'prose lead-in before a poem'    => ["Then he said:\n<pof>Come</pof>", 'Then he said: Come'],
        ];
    }

    #[DataProvider('normalizerCases')]
    public function testPlainTextNormalizer(string $input, string $expected): void
    {
        $stmt = $this->getConnection()->prepare('SELECT bibleget_plain_text(?)');
        $stmt->execute([$input]);
        self::assertSame($expected, $stmt->fetchColumn());
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function embeddingTextCases(): array
    {
        $ps23 = "A psalm of David.\n<pof>The L<sm>ORD</sm> is my shepherd;</pof>\n<poi>there is nothing I lack.</poi>";
        return [
            'psalm superscription dropped'      => [23, $ps23, 'The LORD is my shepherd; there is nothing I lack.'],
            'tagged superscription dropped'     => [23, "<i>Salmo di Davide</i>.\n<pof>L'Eterno è il mio pastore</pof>", "L'Eterno è il mio pastore"],
            'multi-line superscription dropped' => [23, "Alleluja.\nPsalmus.\n<pof>Beati</pof>", 'Beati'],
            'superscription-only verse kept'    => [23, 'For the leader. A psalm of David,', 'For the leader. A psalm of David,'],
            'psalm verse starting with poetry'  => [23, "<po>Even though I walk</po>\n<poi>I fear no harm</poi>", 'Even though I walk I fear no harm'],
            'lead-in outside Psalms kept'       => [1, "ait :\n<pof>Maledictus Chanaan,</pof>", 'ait : Maledictus Chanaan,'],
            'prose psalm verse is just plain'   => [23, 'Psalmus David. Dominus regit me', 'Psalmus David. Dominus regit me'],
        ];
    }

    #[DataProvider('embeddingTextCases')]
    public function testEmbeddingTextLeavesOutPsalmSuperscriptions(int $book, string $input, string $expected): void
    {
        $stmt = $this->getConnection()->prepare('SELECT bibleget_embedding_text(?::int, ?)');
        $stmt->execute([$book, $input]);
        self::assertSame($expected, $stmt->fetchColumn());
    }

    public function testTriggerDerivesTextPlainAndHashesIt(): void
    {
        $row = $this->getConnection()->query(
            'SELECT text, text_plain, text_hash = decode(md5(text_plain), \'hex\') AS hash_ok '
            . 'FROM "TEST1" WHERE book = 1 AND chapter = 3 AND verse = 1'
        )->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertStringContainsString('<sm>', (string) $row['text']);
        self::assertSame(
            'Now the serpent was the most cunning of all the animals that the LORD God had made.',
            $row['text_plain']
        );
        self::assertTrue((bool) $row['hash_ok'], 'text_hash must be the md5 of text_plain');
    }

    public function testTextPlainFollowsTextOnUpdate(): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE "TEST1" SET text = \'<po>Changed L<sm>ORD</sm></po>\' WHERE book = 1 AND chapter = 3 AND verse = 1');
            $plain = $pdo->query('SELECT text_plain FROM "TEST1" WHERE book = 1 AND chapter = 3 AND verse = 1')->fetchColumn();
            self::assertSame('Changed LORD', $plain);
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidPositions(): array
    {
        // Gen 3:2 is "The woman answered the serpent. We may eat …"
        return [
            'zero (use NULL instead)' => [0],
            'splits a word'           => [5],
            'past the end'            => [500],
        ];
    }

    #[DataProvider('invalidPositions')]
    public function testTitlePositionGuardRejects(int $position): void
    {
        $this->assertGuardRejects(2, $position);
    }

    public function testTitlePositionGuardRejectsInsideATag(): void
    {
        // Gen 3:1 starts with "<pof>": offset 3 falls inside the tag.
        $this->assertGuardRejects(1, 3);
    }

    public function testTitlePositionGuardAcceptsTokenBoundaries(): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            // After "serpent. " (space before, letter after) …
            $pdo->exec('UPDATE "TEST1" SET title_position = 32 WHERE book = 1 AND chapter = 3 AND verse = 2');
            // … and right after the full stop (punctuation before, space after).
            $pdo->exec('UPDATE "TEST1" SET title_position = 31 WHERE book = 1 AND chapter = 3 AND verse = 2');
            // Between a closing and an opening tag in formatted text.
            $pos = strpos("<pof>Now the serpent was the most cunning</pof>\n", "\n");
            $pdo->exec('UPDATE "TEST1" SET title_position = ' . (int) $pos . ' WHERE book = 1 AND chapter = 3 AND verse = 1');
            self::assertTrue(true);
        } finally {
            $pdo->rollBack();
        }
    }

    private function assertGuardRejects(int $verse, int $position): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE "TEST1" SET title_position = ' . $position . ' WHERE book = 1 AND chapter = 3 AND verse = ' . $verse);
            self::fail('title_position ' . $position . ' should violate TEST1_title_position_check');
        } catch (\PDOException $e) {
            self::assertStringContainsString('title_position_check', $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }
}
