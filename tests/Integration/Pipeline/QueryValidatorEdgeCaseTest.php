<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Tests\Integration\DatabaseTestCase;

class QueryValidatorEdgeCaseTest extends DatabaseTestCase
{
    private function makeContext(string $query, string $version = 'TEST1'): QuoteContext
    {
        $ctx = new QuoteContext(['query' => $query, 'version' => $version]);
        $ctx->initialize();
        $ctx->queryStrClean();
        return $ctx;
    }

    public function testVerseRangeOutOfBounds(): void
    {
        $ctx = $this->makeContext('Genesis1,8-99');
        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        self::assertNotEmpty($ctx->errors);
        self::assertStringContainsString('out of bounds', $ctx->errors[0]['errMessage']);
    }

    public function testDiscontinuousVerseOutOfBounds(): void
    {
        $ctx = $this->makeContext('Genesis1,1.99');
        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        self::assertNotEmpty($ctx->errors);
    }

    public function testChapterRangeOutOfBounds(): void
    {
        // Genesis only has 3 chapters in test data
        $ctx = $this->makeContext('Genesis1-99');
        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        self::assertNotEmpty($ctx->errors);
    }

    public function testMultipleChapterVerseConstruct(): void
    {
        // Cross-chapter verse range
        $ctx = $this->makeContext('Genesis1,5-2,3');
        $validator = new QueryValidator($ctx);
        $result = $validator->validateQueries();

        self::assertTrue($result);
        self::assertNotEmpty($ctx->validatedQueries);
    }

    public function testVerseRangeWithDash(): void
    {
        $ctx = $this->makeContext('Genesis1,1-10');
        $validator = new QueryValidator($ctx);
        $result = $validator->validateQueries();

        self::assertTrue($result);
    }

    public function testBookImpliedFromPreviousQuery(): void
    {
        // Second query omits book — should inherit from first
        $ctx = $this->makeContext('Genesis1,1;2,1');
        $validator = new QueryValidator($ctx);
        $result = $validator->validateQueries();

        self::assertTrue($result);
        self::assertCount(2, $ctx->validatedQueries);
    }

    public function testMultipleQueriesDifferentBooks(): void
    {
        $ctx = $this->makeContext('Genesis1,1;Exodus1,1');
        $validator = new QueryValidator($ctx);
        $result = $validator->validateQueries();

        self::assertTrue($result);
        self::assertCount(2, $ctx->validatedQueries);
    }

    public function testDiscontinuousVersesWithRange(): void
    {
        // Genesis 1,1-3.5 — verse 1-3, then verse 5
        $ctx = $this->makeContext('Genesis1,1-3.5');
        $validator = new QueryValidator($ctx);
        $result = $validator->validateQueries();

        self::assertTrue($result);
    }
}
