<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\QueryFormulator;
use BibleGet\Api\Pipeline\QueryExecutor;
use BibleGet\Tests\Integration\DatabaseTestCase;

class QueryExecutorIntegrationTest extends DatabaseTestCase
{
    /**
     * Run the full pipeline and return the context with results.
     */
    private function executePipeline(string $query, string $version = 'TEST1'): QuoteContext
    {
        // Set REMOTE_ADDR so IP validation passes
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $ctx = new QuoteContext(['query' => $query, 'version' => $version]);
        $ctx->initialize();
        $ctx->queryStrClean();

        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        $formulator = new QueryFormulator($ctx);
        $formulator->formulateSQLQueries();

        $executor = new QueryExecutor($ctx);
        $executor->executeSQLQueries();

        return $ctx;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    // -- Result retrieval --

    public function testExecuteSingleVerse(): void
    {
        $ctx = $this->executePipeline('Genesis1,1');

        self::assertNotEmpty($ctx->results);
        self::assertCount(1, $ctx->results);

        $verse = $ctx->results[0];
        self::assertSame('TEST1', $verse['version']);
        self::assertSame(1, $verse['chapter']);
        self::assertStringContainsString('In the beginning', $verse['text']);
    }

    public function testExecuteVerseRange(): void
    {
        $ctx = $this->executePipeline('Genesis1,1-3');

        self::assertCount(3, $ctx->results);
        self::assertSame(1, $ctx->results[0]['chapter']);
        self::assertStringContainsString('beginning', $ctx->results[0]['text']);
        self::assertStringContainsString('formless', $ctx->results[1]['text']);
        self::assertStringContainsString('light', $ctx->results[2]['text']);
    }

    public function testExecuteWholeChapter(): void
    {
        $ctx = $this->executePipeline('Genesis1');

        // Genesis chapter 1 has 10 verses in test data
        self::assertCount(10, $ctx->results);
        foreach ($ctx->results as $verse) {
            self::assertSame(1, $verse['chapter']);
        }
    }

    public function testExecuteChapterRange(): void
    {
        $ctx = $this->executePipeline('Genesis1-2');

        // Chapter 1: 10 verses + Chapter 2: 8 verses = 18
        self::assertCount(18, $ctx->results);
    }

    public function testExecuteDiscontinuousVerses(): void
    {
        $ctx = $this->executePipeline('Genesis1,1.3.5');

        self::assertCount(3, $ctx->results);
        self::assertStringContainsString('beginning', $ctx->results[0]['text']);
        self::assertStringContainsString('light', $ctx->results[1]['text']);
        self::assertStringContainsString('day', $ctx->results[2]['text']);
    }

    public function testExecuteMultipleBooks(): void
    {
        $ctx = $this->executePipeline('Genesis1,1;Exodus1,1');

        self::assertCount(2, $ctx->results);
        self::assertStringContainsString('beginning', $ctx->results[0]['text']);
        self::assertStringContainsString('names', $ctx->results[1]['text']);
    }

    public function testExecuteMultipleVersions(): void
    {
        $ctx = $this->executePipeline('Genesis1,1', 'TEST1,TEST2');

        self::assertCount(2, $ctx->results);

        $versions = array_column($ctx->results, 'version');
        self::assertContains('TEST1', $versions);
        self::assertContains('TEST2', $versions);
    }

    // -- Response structure --

    public function testResultHasExpectedFields(): void
    {
        $ctx = $this->executePipeline('Genesis1,1');

        $verse = $ctx->results[0];
        self::assertArrayHasKey('version', $verse);
        self::assertArrayHasKey('book', $verse);
        self::assertArrayHasKey('chapter', $verse);
        self::assertArrayHasKey('verse', $verse);
        self::assertArrayHasKey('text', $verse);
        self::assertArrayHasKey('testament', $verse);
        self::assertArrayHasKey('section', $verse);
        self::assertArrayHasKey('bookabbrev', $verse);
        self::assertArrayHasKey('booknum', $verse);
        self::assertArrayHasKey('univbooknum', $verse);
        self::assertArrayHasKey('originalquery', $verse);
        // verseID should be removed
        self::assertArrayNotHasKey('verseID', $verse);
    }

    public function testResultBookNameResolved(): void
    {
        $ctx = $this->executePipeline('Genesis1,1');

        $verse = $ctx->results[0];
        self::assertSame('Genesis', $verse['book']);
        self::assertSame('Gen', $verse['bookabbrev']);
    }

    public function testResultTypesAreCorrect(): void
    {
        $ctx = $this->executePipeline('Genesis1,1');

        $verse = $ctx->results[0];
        self::assertSame(1, $verse['testament']);
        self::assertSame(1, $verse['section']);
        self::assertSame(1, $verse['chapter']);
    }

    // -- Counter tracking --

    public function testGoodQueryCountIncremented(): void
    {
        $this->executePipeline('Genesis1,1');

        $mysqli = $this->getConnection();
        $result = $mysqli->query('SELECT good FROM counter');
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertNotNull($row);
        self::assertGreaterThan(0, (int) $row['good']);
    }

    // -- Request logging --

    public function testRequestIsLogged(): void
    {
        $this->executePipeline('Genesis1,1');

        $mysqli = $this->getConnection();
        $result = $mysqli->query('SELECT COUNT(*) AS cnt FROM requests_log__2026');
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertNotNull($row);
        self::assertGreaterThan(0, (int) $row['cnt']);
    }

    // -- English notation --

    public function testExecuteEnglishNotation(): void
    {
        $ctx = $this->executePipeline('Genesis1:1-3');

        self::assertSame('ENGLISH', $ctx->detectedNotation);
        self::assertCount(3, $ctx->results);
    }

    // -- Empty/error cases --

    public function testNoResultsForEmptyQuery(): void
    {
        $ctx = new QuoteContext(['query' => '', 'version' => 'TEST1']);
        $ctx->initialize();
        // Don't run pipeline — no query means no results
        self::assertEmpty($ctx->results);
    }
}
