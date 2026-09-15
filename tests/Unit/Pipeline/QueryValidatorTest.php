<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline;

use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryValidatorTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function bookIndicatorProvider(): array
    {
        return [
            'plain book'                    => ['Genesis1,1', true],
            'numbered book 1'               => ['1John3,16', true],
            'numbered book 4 (Vulgate Kgs)' => ['4Kings1,1', true],
            'numbered book 5 (German)'      => ['5Mose1,1', true],
            'numbered book 5 (Hungarian)'   => ['5Móz1:1', true],
            'unicase script'                => ['創世紀1', true],
            'digit beyond max'              => ['6Mose1,1', false],
            'digit only'                    => ['1', false],
            'empty'                         => ['', false],
        ];
    }

    #[DataProvider('bookIndicatorProvider')]
    public function testStartsWithBookIndicator(string $query, bool $expected): void
    {
        self::assertSame($expected, QueryValidator::startsWithBookIndicator($query));
    }

    public function testBookIndicatorBoundMatchesTokenizer(): void
    {
        $max = ReferenceTokenizer::MAX_BOOK_NUMERIC_PREFIX;
        self::assertTrue(QueryValidator::startsWithBookIndicator($max . 'Mose1'));
        self::assertFalse(QueryValidator::startsWithBookIndicator(( $max + 1 ) . 'Mose1'));
    }
}
