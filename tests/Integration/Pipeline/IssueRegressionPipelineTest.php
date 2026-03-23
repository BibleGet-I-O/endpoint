<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\QueryFormulator;
use BibleGet\Api\Pipeline\QueryExecutor;
use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * End-to-end regression tests proving that reported issues are resolved.
 *
 * Each test exercises the full pipeline (normalize → validate → formulate → execute)
 * to confirm the fix works from raw input to final results.
 */
class IssueRegressionPipelineTest extends DatabaseTestCase
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

    /**
     * Issue #41: cross-chapter range with discontinuous verses.
     *
     * Original report: "Matthew 9:35–10:1, 5, 6-8" (English notation)
     * Adapted to test data (European notation): Genesis1,5-2,1.5.6-8
     *   → cross-chapter range 1:5–2:1, then 2:5, then 2:6–2:8
     * Expected: ch1 v5–10 (6) + ch2 v1 (1) + ch2 v5 (1) + ch2 v6–8 (3) = 11 verses
     */
    public function testIssue41CrossChapterRangeWithDiscontinuousVerses(): void
    {
        $ctx = $this->executePipeline('Genesis1,5-2,1.5.6-8');

        self::assertEmpty($ctx->errors, 'Expected no errors');
        self::assertCount(11, $ctx->results);

        // First result is chapter 1 verse 5
        self::assertEquals(1, $ctx->results[0]['chapter']);
        self::assertEquals(5, $ctx->results[0]['verse']);

        // Last result is chapter 2 verse 8
        $last = $ctx->results[count($ctx->results) - 1];
        self::assertEquals(2, $last['chapter']);
        self::assertEquals(8, $last['verse']);
    }

    /**
     * Issue #42: verse "letters" (partial verse suffixes) should be stripped.
     *
     * "Genesis 2,4a" should return verse 4.
     */
    public function testIssue42PartialVerseSuffixReturnsVerse(): void
    {
        $ctx = $this->executePipeline('Genesis2,4a');

        self::assertEmpty($ctx->errors, 'Expected no errors');
        self::assertCount(1, $ctx->results);
        self::assertEquals(4, $ctx->results[0]['verse']);
    }

    /**
     * Issue #42: verse letter range "Genesis 2,4a-7b" should return verses 4–7.
     */
    public function testIssue42PartialVerseSuffixRange(): void
    {
        $ctx = $this->executePipeline('Genesis2,4a-7b');

        self::assertEmpty($ctx->errors, 'Expected no errors');
        self::assertCount(4, $ctx->results);
        self::assertEquals(4, $ctx->results[0]['verse']);
        self::assertEquals(7, $ctx->results[3]['verse']);
    }

    /**
     * Issue #43: "Cf." prefix should be stripped so the query succeeds.
     */
    public function testIssue43CfPrefixStripped(): void
    {
        $ctx = $this->executePipeline('Cf. Genesis 1:1');

        self::assertEmpty($ctx->errors, 'Expected no errors');
        self::assertCount(1, $ctx->results);
        self::assertEquals(1, $ctx->results[0]['chapter']);
        self::assertEquals(1, $ctx->results[0]['verse']);
    }

    /**
     * Issue #43: "Cfr." prefix variant.
     */
    public function testIssue43CfrPrefixStripped(): void
    {
        $ctx = $this->executePipeline('Cfr. Genesis 2:1');

        self::assertEmpty($ctx->errors, 'Expected no errors');
        self::assertCount(1, $ctx->results);
        self::assertEquals(2, $ctx->results[0]['chapter']);
        self::assertEquals(1, $ctx->results[0]['verse']);
    }

    /**
     * Issue #43: prefix on one query in a multi-query string.
     */
    public function testIssue43PrefixOnSecondQuery(): void
    {
        $ctx = $this->executePipeline('Genesis1:1; Cf. Exodus1:1');

        self::assertEmpty($ctx->errors, 'Expected no errors');
        self::assertCount(2, $ctx->results);
        self::assertSame('Genesis', $ctx->results[0]['book']);
        self::assertSame('Exodus', $ctx->results[1]['book']);
    }

    /**
     * Issue #47: out-of-bounds verse in first query must not cause a fatal SQL
     * error in the second query.
     *
     * Original report: "Isaiah8:23;9:1-3" produced a fatal SQL syntax error.
     * Adapted to test data: Genesis1:99 is out of bounds (ch1 has 10 verses),
     * and the second query "2:1" would inherit Genesis.
     *
     * Expected: clean validation error(s), no fatal SQL exception.
     */
    public function testIssue47OutOfBoundsDoesNotCauseFatalSqlError(): void
    {
        $ctx = $this->executePipeline('Genesis1:99;2:1');

        // The key assertion: we get here without a fatal error / exception.
        // First query should produce a validation error.
        self::assertNotEmpty($ctx->errors, 'Expected validation error for out-of-bounds verse');
        self::assertStringContainsString('out of bounds', $ctx->errors[0]['errMessage']);
    }

    /**
     * Issue #47: the second query after an out-of-bounds first query should
     * not produce malformed SQL. If the book is explicitly stated, the second
     * query should succeed independently.
     */
    public function testIssue47ValidSecondQueryAfterInvalidFirst(): void
    {
        // "Genesis1:99" fails validation; "Genesis2:1" is explicit and valid
        $ctx = $this->executePipeline('Genesis1:99;Genesis2:1');

        // First query produces an error
        self::assertNotEmpty($ctx->errors);

        // Second query should still produce a result
        self::assertNotEmpty($ctx->results, 'Valid second query should return results');
        self::assertEquals(2, $ctx->results[0]['chapter']);
        self::assertEquals(1, $ctx->results[0]['verse']);
    }
}
