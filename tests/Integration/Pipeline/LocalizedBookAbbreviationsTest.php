<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\Parser\ReferenceParser;
use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use BibleGet\Api\Pipeline\Validator\AstValidator;
use BibleGet\Api\Util\StringUtils;
use BibleGet\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The abbreviations French, German, Portuguese and Spanish citations actually use (056) resolve to the right book,
 * through the real book tables and the validator's two passes (exact, then diacritics folded).
 *
 * The cases are the citations the SeminaVerbi wikis wrote and BibleGet rejected or misread, plus a few that pin
 * the tokens 056 could not add because another language already uses them for another book.
 */
final class LocalizedBookAbbreviationsTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{string, int}> citation as written, book number (biblebooks_*."BOOK")
     */
    public static function citations(): array
    {
        return [
            // es and fr: Samuel and Kings by a single letter.
            'es/fr 1 R'            => ['1 R 19,12', 11],
            'es/fr 2 R'            => ['2 R 2,11', 12],
            'es/fr 1 S'            => ['1 S 3,10', 9],
            'es/fr 2 S'            => ['2 S 7,12', 10],
            // de (Einheitsübersetzung, Loccum).
            'de Joh'               => ['Joh 3,16', 50],
            'de 1 Joh'             => ['1 Joh 4,8', 69],
            'de Offb'              => ['Offb 21,1', 73],
            'de Hebr'              => ['Hebr 11,1', 65],
            'de Apg'               => ['Apg 2,1', 51],
            'de 1 Petr'            => ['1 Petr 3,15', 67],
            'de 2 Petr'            => ['2 Petr 1,4', 68],
            'de Jes'               => ['Jes 7,14', 29],
            'de Spr'               => ['Spr 8,22', 24],
            'de Hld'               => ['Hld 8,6', 26],
            'de 1 Kön'             => ['1 Kön 19,12', 11],
            'de Dtn'               => ['Dtn 6,4', 5],
            'de Klgl'              => ['Klgl 3,22', 31],
            'de Sach'              => ['Sach 9,9', 45],
            'de Röm'               => ['Röm 8,28', 52],
            'de Joël'              => ['Joël 3,1', 36],
            // pt (CNBB, Ave-Maria).
            'pt 1 Pd'              => ['1 Pd 3,15', 67],
            'pt 2 Pd'              => ['2 Pd 1,4', 68],
            'pt 1 Jo'              => ['1 Jo 4,8', 69],
            'pt Sl'                => ['Sl 23,1', 23],
            'pt Mq'                => ['Mq 5,1', 40],
            'pt Fl'                => ['Fl 2,7', 57],
            'pt 1 Rs'              => ['1 Rs 19,12', 11],
            'pt Tg'                => ['Tg 2,17', 66],
            'pt Eclo'              => ['Eclo 24,1', 28],
            'pt Jó'                => ['Jó 15,35', 22],
            'pt Gál'               => ['Gál 5,13', 55],
            // fr (Bible de Jérusalem): the alternates for tokens other languages hold.
            'fr Hé'                => ['Hé 11,1', 65],
            'fr Jac'               => ['Jac 2,17', 66],
            'fr Sag'               => ['Sag 7,26', 27],
            'fr Abd'               => ['Abd 1,15', 38],
            // Tokens 056 leaves to the language that already had them.
            'it Gl stays Joel'     => ['Gl 3,1', 36],
            'hu Jo stays Joel'     => ['Jo 3,1', 36],
            'Jn stays John'        => ['Jn 3,16', 50],
            'es He stays Acts'     => ['He 2,1', 51],
            'es Jc stays Judges'   => ['Jc 6,12', 7],
            'it Ab stays Habakkuk' => ['Ab 2,4', 42],
            'hr Br stays Numbers'  => ['Br 6,24', 4],
            'en Sg stays Song'     => ['Sg 8,6', 26],
        ];
    }

    #[DataProvider('citations')]
    public function testTheCitationResolvesToItsBook(string $citation, int $book): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();
        self::assertCount(73, $ctx->BIBLEBOOKS);

        // An index that holds every book with room for any chapter and verse: only the book is under test.
        $index     = [
            'abbreviations' => array_fill(0, 73, ''),
            'biblebooks'    => array_fill(0, 73, ''),
            'chapter_limit' => array_fill(0, 73, 200),
            'verse_limit'   => array_fill(0, 73, array_fill(0, 200, 200)),
            'book_num'      => range(1, 73),
        ];
        $validator = new AstValidator($ctx->BIBLEBOOKS, ['ALL' => $index], ['ALL']);

        // What queryStrClean() hands the tokenizer: whitespace removed, proper-cased.
        $query  = StringUtils::toProperCase((string) preg_replace('/\s+/u', '', $citation));
        $tokens = ( new ReferenceTokenizer() )->tokenize($query);
        $result = $validator->validate(( new ReferenceParser() )->parse($tokens), $tokens);

        self::assertNotNull($result, $citation . ': ' . implode('; ', array_map(static fn($e) => $e->message, $validator->getErrors())));
        self::assertSame($book, $result->book, $citation);
    }
}
