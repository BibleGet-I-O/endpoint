<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\QueryFormulator;
use BibleGet\Api\Pipeline\QueryExecutor;
use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * End-to-end pipeline tests covering additional code paths:
 * cross-chapter ranges, discontinuous + range combos, and
 * multi-version scenarios.
 */
class FullPipelineTest extends DatabaseTestCase
{
    private function executePipeline(string $query, string $version = 'TEST1'): QuoteContext
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $ctx = new QuoteContext(['query' => $query, 'version' => $version]);
        $ctx->initialize();
        $ctx->queryStrClean();

        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        if (!empty($ctx->validatedQueries)) {
            $formulator = new QueryFormulator($ctx);
            $formulator->formulateSQLQueries();

            $executor = new QueryExecutor($ctx);
            $executor->executeSQLQueries();
        }

        return $ctx;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    public function testCrossChapterRange(): void
    {
        // Genesis 1,5 through 2,3
        $ctx = $this->executePipeline('Genesis1,5-2,3');

        // Chapter 1 verses 5-10 = 6 verses + Chapter 2 verses 1-3 = 3 verses = 9
        self::assertCount(9, $ctx->results);
        self::assertSame(1, $ctx->results[0]['chapter']);
        self::assertSame(2, $ctx->results[count($ctx->results) - 1]['chapter']);
    }

    public function testDiscontinuousWithRange(): void
    {
        // Genesis 1,1-3.5 — verses 1-3 then verse 5
        $ctx = $this->executePipeline('Genesis1,1-3.5');

        self::assertCount(4, $ctx->results);
    }

    public function testDiscontinuousWithRangeOnSecondPart(): void
    {
        // Genesis 1,1.3-5 — verse 1, then verses 3-5
        $ctx = $this->executePipeline('Genesis1,1.3-5');

        self::assertCount(4, $ctx->results);
    }

    public function testMultipleBooksInSingleQuery(): void
    {
        $ctx = $this->executePipeline('Genesis1,1;Exodus1,1;Genesis2,1');

        self::assertCount(3, $ctx->results);
        self::assertSame('Genesis', $ctx->results[0]['book']);
        self::assertSame('Exodus', $ctx->results[1]['book']);
        self::assertSame('Genesis', $ctx->results[2]['book']);
    }

    public function testMultipleVersionsSameQuery(): void
    {
        $ctx = $this->executePipeline('Genesis1,1-3', 'TEST1,TEST2');

        // 3 verses from TEST1 + 3 verses from TEST2 = 6
        self::assertCount(6, $ctx->results);
    }

    public function testWholeChapterMultipleVersions(): void
    {
        $ctx = $this->executePipeline('Genesis1', 'TEST1,TEST2');

        // TEST1 has 10 verses in ch1, TEST2 has 5 = 15
        self::assertCount(15, $ctx->results);
    }

    public function testChapterRangeResults(): void
    {
        // Genesis chapters 1-2
        $ctx = $this->executePipeline('Genesis1-2');

        // Ch1 = 10 + Ch2 = 8 = 18
        self::assertCount(18, $ctx->results);
        // Verify ordering
        $prevChapter = 0;
        foreach ($ctx->results as $verse) {
            self::assertGreaterThanOrEqual($prevChapter, $verse['chapter']);
            $prevChapter = $verse['chapter'];
        }
    }

    public function testBookImpliedFromPreviousQuery(): void
    {
        // "Genesis1,1;2,1" — second query inherits Genesis
        $ctx = $this->executePipeline('Genesis1,1;2,1');

        self::assertCount(2, $ctx->results);
        self::assertSame('Genesis', $ctx->results[0]['book']);
        self::assertSame('Genesis', $ctx->results[1]['book']);
        self::assertSame(1, $ctx->results[0]['chapter']);
        self::assertSame(2, $ctx->results[1]['chapter']);
    }

    public function testCopyrightVersionQueriesWork(): void
    {
        // TEST2 is copyright — queries should still work, just with LIMIT
        $ctx = $this->executePipeline('Genesis1', 'TEST2');

        self::assertNotEmpty($ctx->results);
        foreach ($ctx->results as $verse) {
            self::assertSame('TEST2', $verse['version']);
        }
    }

    public function testOriginalQueryPreservedInResults(): void
    {
        $ctx = $this->executePipeline('Genesis1,1');

        self::assertSame('Genesis1,1', $ctx->results[0]['originalquery']);
    }

    public function testEnglishNotationFullPipeline(): void
    {
        $ctx = $this->executePipeline('Genesis1:1-3');

        self::assertSame('ENGLISH', $ctx->detectedNotation);
        self::assertCount(3, $ctx->results);
    }

    public function testErrorsCollectedForInvalidBook(): void
    {
        $ctx = $this->executePipeline('FakeBook1,1');

        self::assertEmpty($ctx->results);
        self::assertNotEmpty($ctx->errors);
    }
}
