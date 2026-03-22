<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Tests\Integration\DatabaseTestCase;

class QueryValidatorIntegrationTest extends DatabaseTestCase
{
    private function makeContext(string $query, string $version = 'TEST1'): QuoteContext
    {
        $ctx = new QuoteContext(['query' => $query, 'version' => $version]);
        $ctx->initialize();
        $ctx->queryStrClean();
        return $ctx;
    }

    // -- Valid queries --

    public function testValidateSingleChapterVerse(): void
    {
        $ctx       = $this->makeContext('Genesis1,1');
        $validator = new QueryValidator($ctx);
        $result    = $validator->validateQueries();

        self::assertTrue($result);
        self::assertNotEmpty($ctx->validatedQueries);
        self::assertSame('Genesis1,1', $ctx->validatedQueries[0]);
    }

    public function testValidateVerseRange(): void
    {
        $ctx       = $this->makeContext('Genesis1,1-3');
        $validator = new QueryValidator($ctx);
        $result    = $validator->validateQueries();

        self::assertTrue($result);
        self::assertNotEmpty($ctx->validatedQueries);
    }

    public function testValidateWholeChapter(): void
    {
        $ctx       = $this->makeContext('Genesis1');
        $validator = new QueryValidator($ctx);
        $result    = $validator->validateQueries();

        self::assertTrue($result);
        self::assertNotEmpty($ctx->validatedQueries);
    }

    public function testValidateChapterRange(): void
    {
        $ctx       = $this->makeContext('Genesis1-2');
        $validator = new QueryValidator($ctx);
        $result    = $validator->validateQueries();

        self::assertTrue($result);
        self::assertNotEmpty($ctx->validatedQueries);
    }

    public function testValidateDiscontinuousVerses(): void
    {
        $ctx       = $this->makeContext('Genesis1,1.3.5');
        $validator = new QueryValidator($ctx);
        $result    = $validator->validateQueries();

        self::assertTrue($result);
        self::assertNotEmpty($ctx->validatedQueries);
    }

    public function testValidateMultipleQueries(): void
    {
        $ctx       = $this->makeContext('Genesis1,1;Exodus1,1');
        $validator = new QueryValidator($ctx);
        $result    = $validator->validateQueries();

        self::assertTrue($result);
        self::assertCount(2, $ctx->validatedQueries);
    }

    public function testValidateEnglishNotation(): void
    {
        $ctx = $this->makeContext('Genesis1:1');
        // English notation gets auto-converted to European
        self::assertSame('ENGLISH', $ctx->detectedNotation);

        $validator = new QueryValidator($ctx);
        $result    = $validator->validateQueries();

        self::assertTrue($result);
        self::assertNotEmpty($ctx->validatedQueries);
    }

    // -- Invalid queries --

    public function testInvalidBookName(): void
    {
        $ctx       = $this->makeContext('Fakebook1,1');
        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        // Should have no validated queries, but errors
        self::assertEmpty($ctx->validatedQueries);
        self::assertNotEmpty($ctx->errors);
    }

    public function testChapterOutOfBounds(): void
    {
        $ctx       = $this->makeContext('Genesis99,1');
        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        self::assertNotEmpty($ctx->errors);
        self::assertStringContainsString('out of bounds', $ctx->errors[0]['errMessage']);
    }

    public function testVerseOutOfBounds(): void
    {
        $ctx       = $this->makeContext('Genesis1,99');
        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        self::assertNotEmpty($ctx->errors);
        self::assertStringContainsString('out of bounds', $ctx->errors[0]['errMessage']);
    }

    public function testQueryMustStartWithBook(): void
    {
        $ctx       = $this->makeContext('1,1');
        $validator = new QueryValidator($ctx);
        $result    = $validator->validateQueries();

        self::assertFalse($result);
    }

    public function testValidatedVariantsPopulated(): void
    {
        $ctx       = $this->makeContext('Genesis1,1');
        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        self::assertNotEmpty($ctx->validatedVariants);
        self::assertContains('TEST1', $ctx->validatedVariants[0]);
    }

    public function testMultipleVersionsValidatedVariants(): void
    {
        $ctx       = $this->makeContext('Genesis1,1', 'TEST1,TEST2');
        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        self::assertNotEmpty($ctx->validatedVariants);
        // Both versions should have Genesis
        $allVariants = array_merge(...$ctx->validatedVariants);
        self::assertContains('TEST1', $allVariants);
        self::assertContains('TEST2', $allVariants);
    }
}
