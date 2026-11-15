<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Database;

use BibleGet\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * bibleget_markup_ok() and the <T>_markup_check constraint (migration 030):
 * known tags only, matched and nested per line, no line tag in a line tag.
 */
final class MarkupTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->getConnection()->query("SELECT bibleget_markup_ok('')");
        } catch (\PDOException $e) {
            self::markTestSkipped('bibleget_markup_ok() not in the test schema: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function cases(): array
    {
        return [
            'prose'                         => ['In the beginning.', true],
            'poetic lines'                  => ["<pof>The L<sm>ORD</sm> is my shepherd;</pof>\n<poi>there is nothing I lack.</poi>", true],
            'untagged lead-in'              => ["A psalm of David.\n<pof>The Lord</pof>", true],
            'speaker and italics in a line' => ['<pof><speaker>W</speaker> Let him <i>kiss</i> me</pof>', true],
            'comparison sign is not a tag'  => ['a < b and c > d', true],
            'line break inside a pair'      => ["<po>\nThorns and thistles</po>", false],
            'line break before closing tag' => ["<po>Your power \n</po>", false],
            'mismatched closing tag'        => ['<po>a</poi>', false],
            'unclosed tag'                  => ['<pof>a', false],
            'stray closing tag'             => ['a</po>', false],
            'line tag inside a line tag'    => ['<po><poi>a</poi></po>', false],
            'unknown tag'                   => ['<b>bold</b>', false],
            'tag with attributes'           => ['<po class="x">a</po>', false],
            'self-closing tag'              => ['a<br/>b', false],
        ];
    }

    #[DataProvider('cases')]
    public function testMarkupValidator(string $text, bool $expected): void
    {
        $stmt = $this->getConnection()->prepare('SELECT bibleget_markup_ok(?)');
        $stmt->execute([$text]);
        self::assertSame($expected, $stmt->fetchColumn());
    }

    public function testConstraintRejectsMalformedText(): void
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/TEST1_markup_check/');
        $this->getConnection()->exec(
            "UPDATE \"TEST1\" SET text = '<po>\nbroken</po>' WHERE book = 1 AND chapter = 1 AND verse = 1"
        );
    }

    public function testConstraintAcceptsWellFormedText(): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $n = $pdo->exec(
                "UPDATE \"TEST1\" SET text = '<pof>In the beginning</pof>' WHERE book = 1 AND chapter = 1 AND verse = 1"
            );
            self::assertSame(1, $n);
        } finally {
            $pdo->rollBack();
        }
    }
}
