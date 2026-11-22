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
 * The abbreviations French, German, Portuguese and Spanish (056), Romanian, Czech, Albanian and Vietnamese (057)
 * citations actually use, and the names 061 adds (Atos, Atos dos Apóstolos, Actes des Apôtres, 1/2 Thes), resolve to
 * the right book,
 * through the real book tables and the validator's two passes (exact, then diacritics folded).
 *
 * The cases are the citations the SeminaVerbi wikis wrote and BibleGet rejected or misread, each language's
 * standard forms, plus a few that pin the tokens 056 and 057 could not add because another language already uses
 * them for another book.
 */
final class LocalizedBookAbbreviationsTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{string, int}> citation as written, book number (biblebooks_*."BOOK")
     */
    public static function citations(): array
    {
        return [
            // 061: Acts in Brazilian Portuguese and in full French; the common English 1/2 Thes (SeminaVerbi pilot).
            'pt Atos'               => ['Atos 2,1-4', 51],
            'pt Atos dos Apóstolos' => ['Atos dos Apóstolos 17,16-34', 51],
            'fr Actes des Apôtres'  => ['Actes des Apôtres 17,16-34', 51],
            'en 1 Thes'             => ['1 Thes 4,13-14', 59],
            'en 2 Thes'             => ['2 Thes 2,15', 60],
            // es and fr: Samuel and Kings by a single letter.
            'es/fr 1 R'             => ['1 R 19,12', 11],
            'es/fr 2 R'             => ['2 R 2,11', 12],
            'es/fr 1 S'             => ['1 S 3,10', 9],
            'es/fr 2 S'             => ['2 S 7,12', 10],
            // de (Einheitsübersetzung, Loccum).
            'de Joh'                => ['Joh 3,16', 50],
            'de 1 Joh'              => ['1 Joh 4,8', 69],
            'de Offb'               => ['Offb 21,1', 73],
            'de Hebr'               => ['Hebr 11,1', 65],
            'de Apg'                => ['Apg 2,1', 51],
            'de 1 Petr'             => ['1 Petr 3,15', 67],
            'de 2 Petr'             => ['2 Petr 1,4', 68],
            'de Jes'                => ['Jes 7,14', 29],
            'de Spr'                => ['Spr 8,22', 24],
            'de Hld'                => ['Hld 8,6', 26],
            'de 1 Kön'              => ['1 Kön 19,12', 11],
            'de Dtn'                => ['Dtn 6,4', 5],
            'de Klgl'               => ['Klgl 3,22', 31],
            'de Sach'               => ['Sach 9,9', 45],
            'de Röm'                => ['Röm 8,28', 52],
            'de Joël'               => ['Joël 3,1', 36],
            // pt (CNBB, Ave-Maria).
            'pt 1 Pd'               => ['1 Pd 3,15', 67],
            'pt 2 Pd'               => ['2 Pd 1,4', 68],
            'pt 1 Jo'               => ['1 Jo 4,8', 69],
            'pt Sl'                 => ['Sl 23,1', 23],
            'pt Mq'                 => ['Mq 5,1', 40],
            'pt Fl'                 => ['Fl 2,7', 57],
            'pt 1 Rs'               => ['1 Rs 19,12', 11],
            'pt Tg'                 => ['Tg 2,17', 66],
            'pt Eclo'               => ['Eclo 24,1', 28],
            'pt Jó'                 => ['Jó 15,35', 22],
            'pt Gál'                => ['Gál 5,13', 55],
            // fr (Bible de Jérusalem): the alternates for tokens other languages hold.
            'fr Hé'                 => ['Hé 11,1', 65],
            'fr Jac'                => ['Jac 2,17', 66],
            'fr Sag'                => ['Sag 7,26', 27],
            'fr Abd'                => ['Abd 1,15', 38],
            // Tokens 056 leaves to the language that already had them.
            'it Gl stays Joel'      => ['Gl 3,1', 36],
            'hu Jo stays Joel'      => ['Jo 3,1', 36],
            'Jn stays John'         => ['Jn 3,16', 50],
            'es He stays Acts'      => ['He 2,1', 51],
            'es Jc stays Judges'    => ['Jc 6,12', 7],
            'it Ab stays Habakkuk'  => ['Ab 2,4', 42],
            'hr Br stays Numbers'   => ['Br 6,24', 4],
            'en Sg stays Song'      => ['Sg 8,6', 26],
            // ro (Catholic ARCB, Cornilescu).
            'ro Fac'                => ['Fac 1,1', 1],
            'ro Ieș'                => ['Ieș 3,14', 2],
            'ro 1 Rg'               => ['1 Rg 19,12', 11],
            'ro 1 Împ'              => ['1 Împ 19,12', 11],
            'ro Înț'                => ['Înț 7,26', 27],
            'ro Plâng'              => ['Plâng 3,22', 31],
            'ro Il'                 => ['Il 3,1', 36],
            'ro In'                 => ['In 3,16', 50],
            'ro Fap'                => ['Fap 2,1', 51],
            'ro Evr'                => ['Evr 11,1', 65],
            'ro Iac'                => ['Iac 2,17', 66],
            'ro 1 In'               => ['1 In 4,8', 69],
            // cs (ČEP, Catholic liturgical).
            'cs Sd'                 => ['Sd 6,12', 7],
            'cs 1 Kr'               => ['1 Kr 19,12', 11],
            'cs 1 Pa'               => ['1 Pa 1,1', 13],
            'cs Ž'                  => ['Ž 23,1', 23],
            'cs Kaz'                => ['Kaz 1,2', 25],
            'cs Mdr'                => ['Mdr 7,26', 27],
            'cs L'                  => ['L 1,1', 49],
            'cs J'                  => ['J 3,16', 50],
            'cs Sk'                 => ['Sk 2,1', 51],
            'cs Ř'                  => ['Ř 8,28', 52],
            'cs Žd'                 => ['Žd 11,1', 65],
            'cs 1 Petr'             => ['1 Petr 3,15', 67],
            'cs Zj'                 => ['Zj 21,1', 73],
            // sq.
            'sq Zan'                => ['Zan 1,1', 1],
            'sq Dal'                => ['Dal 3,14', 2],
            'sq 1 Mb'               => ['1 Mb 19,12', 11],
            'sq 1 Kron'             => ['1 Kron 1,1', 13],
            'sq Llu'                => ['Llu 1,1', 49],
            'sq Gjn'                => ['Gjn 3,16', 50],
            'sq Vep'                => ['Vep 2,1', 51],
            'sq Zb'                 => ['Zb 21,1', 73],
            // vi (Nhóm Phiên Dịch Các Giờ Kinh Phụng Vụ).
            'vi Xh'                 => ['Xh 3,14', 2],
            'vi Đnl'                => ['Đnl 6,4', 5],
            'vi R'                  => ['R 1,16', 8],
            'vi 1 V'                => ['1 V 19,12', 11],
            'vi Gđt'                => ['Gđt 8,1', 18],
            'vi Tv'                 => ['Tv 23,1', 23],
            'vi Hc'                 => ['Hc 24,1', 28],
            'vi Cv'                 => ['Cv 2,1', 51],
            'vi Gc'                 => ['Gc 2,17', 66],
            'vi 1 Ga'               => ['1 Ga 4,8', 69],
            'vi Kh'                 => ['Kh 21,1', 73],
            // Tokens 057 leaves to the language that already had them.
            'es St stays James'     => ['St 1,1', 66],
            'Gn stays Genesis'      => ['Gn 1,1', 1],
            'Dt stays Deuteronomy'  => ['Dt 6,4', 5],
            'Ga stays Galatians'    => ['Ga 2,1', 55],
            'af Pl stays Philemon'  => ['Pl 1,1', 64],
            'Jud stays Jude'        => ['Jud 1,3', 72],
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
