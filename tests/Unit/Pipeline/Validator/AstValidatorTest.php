<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Validator;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\Parser\ReferenceParser;
use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use BibleGet\Api\Pipeline\Tokenizer\Token;
use BibleGet\Api\Pipeline\Validator\AstValidator;
use PHPUnit\Framework\TestCase;

final class AstValidatorTest extends TestCase
{
    private ReferenceTokenizer $tokenizer;
    private ReferenceParser $parser;

    /**
     * Minimal BIBLEBOOKS array: index 0 = Genesis (book_num 1), index 18 = Psalms (book_num 19).
     * Each entry: [language_idx => [fullname, abbreviation, ...normalized forms]]
     *
     * @var array<int, array<int, array<int, string>>>
     */
    private array $bibleBooks;

    /**
     * Minimal index for a single version "TST" (test).
     *
     * @var array<string, array{abbreviations: array<int, string>, biblebooks: array<int, string>, chapter_limit: array<int, int>, verse_limit: array<int, array<int, int>>, book_num: array<int, int>}>
     */
    private array $indexes;

    protected function setUp(): void
    {
        $this->tokenizer = new ReferenceTokenizer();
        $this->parser    = new ReferenceParser();

        // Genesis at index 0 (book_num = 1): 50 chapters, chapter 1 has 31 verses, chapter 2 has 25 verses
        // Psalms at index 18 (book_num = 19): 150 chapters
        $this->bibleBooks = [];
        for ($i = 0; $i < 73; $i++) {
            $this->bibleBooks[$i] = [];
        }
        $this->bibleBooks[0]  = [1 => ['Genesis', 'Gen', 'Genesis', 'Gen']];
        $this->bibleBooks[18] = [1 => ['Psalms', 'Ps', 'Psalms', 'Ps']];
        $this->bibleBooks[42] = [1 => ['John', 'Jn', 'John', 'Jn']];
        $this->bibleBooks[61] = [1 => ['1John', '1Jn', '1John', '1Jn']];

        $this->indexes = [
            'TST' => [
                'abbreviations' => ['Gen', 'Ps', 'Jn', '1Jn'],
                'biblebooks'    => ['Genesis', 'Psalms', 'John', '1John'],
                'chapter_limit' => [50, 150, 21, 5],
                'verse_limit'   => [
                    // Genesis: chapters 1-50, but we only fill first few for testing
                    array_merge([31, 25, 24], array_fill(0, 47, 30)),
                    // Psalms: 150 chapters
                    array_fill(0, 150, 20),
                    // John: 21 chapters
                    array_merge([51, 25, 36], array_fill(0, 18, 25)),
                    // 1John: 5 chapters
                    array_fill(0, 5, 21),
                ],
                'book_num'      => [1, 19, 43, 62],
            ],
        ];
    }

    private function createValidator(): AstValidator
    {
        return new AstValidator($this->bibleBooks, $this->indexes, ['TST']);
    }

    /**
     * @return array{BibleQuery, Token[]}
     */
    private function tokenizeAndParse(string $input): array
    {
        $tokens = $this->tokenizer->tokenize($input);
        $query  = $this->parser->parse($tokens);
        return [$query, $tokens];
    }

    // ── Valid references ──────────────────────────────────────────

    public function testValidWholeChapter(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Genesis1');
        $result           = $validator->validate($query, $tokens);

        $this->assertNotNull($result);
        $this->assertSame(1, $result->book);
        $this->assertCount(1, $result->segments);
        $this->assertEmpty($validator->getErrors());
    }

    public function testValidSingleVerse(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Genesis1,5');
        $result           = $validator->validate($query, $tokens);

        $this->assertNotNull($result);
        $this->assertSame(1, $result->book);
        $this->assertEmpty($validator->getErrors());
    }

    public function testValidVerseRange(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Genesis1,5-10');
        $result           = $validator->validate($query, $tokens);

        $this->assertNotNull($result);
        $this->assertEmpty($validator->getErrors());
    }

    public function testValidCrossChapterRange(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Genesis1,5-2,3');
        $result           = $validator->validate($query, $tokens);

        $this->assertNotNull($result);
        $this->assertEmpty($validator->getErrors());
    }

    public function testValidNumberedBook(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('1John3,16');
        $result           = $validator->validate($query, $tokens);

        $this->assertNotNull($result);
        $this->assertSame(62, $result->book);
        $this->assertEmpty($validator->getErrors());
    }

    // ── Invalid book ──────────────────────────────────────────────

    public function testInvalidBookName(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Foobar1,1');
        $result           = $validator->validate($query, $tokens);

        $this->assertNull($result);
        $this->assertNotEmpty($validator->getErrors());
        $this->assertStringContainsString('not a valid Bible book', $validator->getErrors()[0]->message);
    }

    // ── Chapter out of bounds ─────────────────────────────────────

    public function testChapterOutOfBounds(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Genesis99');
        $result           = $validator->validate($query, $tokens);

        $this->assertNull($result);
        $this->assertNotEmpty($validator->getErrors());
        $this->assertStringContainsString('chapter', $validator->getErrors()[0]->message);
        $this->assertStringContainsString('out of bounds', $validator->getErrors()[0]->message);
    }

    // ── Verse out of bounds ───────────────────────────────────────

    public function testVerseOutOfBounds(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Genesis1,99');
        $result           = $validator->validate($query, $tokens);

        $this->assertNull($result);
        $this->assertNotEmpty($validator->getErrors());
        $this->assertStringContainsString('verse', $validator->getErrors()[0]->message);
        $this->assertStringContainsString('out of bounds', $validator->getErrors()[0]->message);
    }

    // ── Range ordering ────────────────────────────────────────────

    public function testInvalidRangeVerseOrder(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Genesis1,10-5');
        $result           = $validator->validate($query, $tokens);

        $this->assertNull($result);
        $this->assertNotEmpty($validator->getErrors());
        $this->assertStringContainsString('start verse', $validator->getErrors()[0]->message);
    }

    public function testInvalidRangeChapterOrder(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Genesis3,1-1,5');
        $result           = $validator->validate($query, $tokens);

        $this->assertNull($result);
        $this->assertNotEmpty($validator->getErrors());
        $this->assertStringContainsString('start chapter', $validator->getErrors()[0]->message);
    }

    // ── Validated variants ────────────────────────────────────────

    public function testValidatedVariantsPopulated(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Genesis1');
        $validator->validate($query, $tokens);

        $this->assertSame(['TST'], $validator->getValidatedVariants());
    }

    public function testValidatedVariantsEmptyForMissingBook(): void
    {
        $validator        = $this->createValidator();
        [$query, $tokens] = $this->tokenizeAndParse('Foobar1');
        $validator->validate($query, $tokens);

        $this->assertEmpty($validator->getValidatedVariants());
    }
}
