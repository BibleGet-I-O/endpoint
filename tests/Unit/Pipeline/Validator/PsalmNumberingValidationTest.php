<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Validator;

use BibleGet\Api\Pipeline\Parser\ReferenceParser;
use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use BibleGet\Api\Pipeline\Validator\AstValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #210: a Psalm reference is bounds-checked against each version as that
 * version stores it, so a citation is accepted wherever its psalm exists, in
 * either order of its two numbers.
 *
 * The verse tables are real: HEB is the Nova Vulgata's (Hebrew numbering), VGCL
 * the Clementine Vulgate's.
 */
final class PsalmNumberingValidationTest extends TestCase
{
    private const HEBREW_VERSES = '6,12,9,9,13,11,18,10,21,18,7,8,6,7,5,11,15,51,15,10,14,32,6,10,22,12,14,9,11,13,25,11,22,23,28,13,40,23,'
        . '14,18,14,12,5,26,18,12,10,15,21,23,21,11,7,9,24,14,12,12,18,13,9,13,12,11,14,20,8,36,37,6,24,19,28,23,11,13,21,72,13,20,17,8,'
        . '19,13,14,17,7,19,53,17,16,16,5,24,11,13,12,9,9,5,8,29,22,35,45,48,43,14,31,7,10,10,9,8,18,19,2,29,176,7,8,9,4,8,5,6,5,6,8,8,3,'
        . '18,3,3,21,26,9,8,24,14,10,8,12,15,21,10,20,14,9,5';

    private const VULGATE_VERSES = '6,13,9,10,13,11,18,10,39,8,9,6,7,5,10,15,51,15,10,14,32,6,10,22,12,14,9,11,13,25,11,22,23,28,13,40,23,'
        . '14,18,14,12,5,26,18,12,10,15,21,23,21,11,7,9,24,13,12,12,18,14,9,13,12,11,14,20,8,36,37,6,24,20,28,23,11,13,21,72,13,20,17,8,'
        . '19,13,14,17,7,19,53,17,16,16,5,23,11,13,12,9,9,5,8,29,22,35,45,48,43,14,31,7,10,10,9,26,9,10,2,29,176,7,8,9,4,8,5,6,5,6,8,8,3,'
        . '18,3,3,21,26,9,8,24,14,10,8,12,15,21,10,11,9,14,9,6';

    /**
     * @param string[] $versions
     */
    private function validator(array $versions): AstValidator
    {
        $bibleBooks     = array_fill(0, 73, []);
        $bibleBooks[22] = [1 => ['Psalms', 'Psalm', 'Ps', 'Salmo', 'Psalmo']];
        $index          = static fn(string $verses): array => [
            'abbreviations' => ['Ps'],
            'biblebooks'    => ['Psalms'],
            'chapter_limit' => [150],
            'verse_limit'   => [array_map('intval', explode(',', $verses))],
            'book_num'      => [23],
        ];
        $indexes        = ['HEB' => $index(self::HEBREW_VERSES), 'VGCL' => $index(self::VULGATE_VERSES)];

        return new AstValidator($bibleBooks, $indexes, $versions);
    }

    /**
     * @param string[] $versions
     * @return string[] the error messages
     */
    private function errorsFor(string $input, array $versions): array
    {
        $tokens    = ( new ReferenceTokenizer() )->tokenize($input);
        $validator = $this->validator($versions);
        $validator->validate(( new ReferenceParser() )->parse($tokens), $tokens);

        return array_map(static fn($e): string => $e->message, $validator->getErrors());
    }

    public function testTheTablesHaveEveryPsalm(): void
    {
        self::assertCount(150, explode(',', self::HEBREW_VERSES));
        self::assertCount(150, explode(',', self::VULGATE_VERSES));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function citationsValidInBothNumberings(): array
    {
        return [
            // Hebrew 131 has 3 verses, so this was rejected when the first number was taken as Hebrew.
            'CEI, Vulgate first'          => ['Salmo131(132),6-7.9-10.13-14'],
            'Nova Vulgata, Hebrew first'  => ['Psalm132(131),6-7.9-10.13-14'],
            'Latin lectionary'            => ['Psalmo67(68),20'],
            // Vulgate 115 has 10 verses; Hebrew 115:15 is Vulgate 113:23.
            'bare Hebrew in a split zone' => ['Psalm115,15'],
            'Hebrew 10, Vulgate 9:22-39'  => ['Psalm9(10),1-18'],
            'Hebrew 147:12-20'            => ['Salmo147(147),12-20'],
        ];
    }

    #[DataProvider('citationsValidInBothNumberings')]
    public function testACitationIsValidWhereverItsPsalmExists(string $input): void
    {
        self::assertSame([], $this->errorsFor($input, ['HEB', 'VGCL']));
    }

    public function testAVerseOutOfBoundsNamesTheVersionAndItsOwnChapter(): void
    {
        // Hebrew 16:11 is Vulgate 15:11, but the Clementine Ps 15 has 10 verses (v. 10 holds both).
        self::assertSame([], $this->errorsFor('Psalmo15(16),11', ['HEB']));

        $errors = $this->errorsFor('Psalmo15(16),11', ['HEB', 'VGCL']);
        self::assertCount(1, $errors);
        self::assertStringContainsString('<15>', $errors[0]);
        self::assertStringContainsString('VGCL', $errors[0]);
        self::assertStringContainsString('<10>', $errors[0]);
    }

    public function testAShapeErrorIsReportedOnceForAllVersions(): void
    {
        $errors = $this->errorsFor('Psalm51,5-3', ['HEB', 'VGCL']);

        self::assertCount(1, $errors);
        self::assertStringContainsString('Invalid range', $errors[0]);
    }
}
