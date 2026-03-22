<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\QueryFormulator;
use BibleGet\Api\Pipeline\QueryExecutor;
use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * Integration tests for issue #46: Psalm references with dual Hebrew/Vulgate numbering.
 *
 * Verifies that "Psalm51(50),1" queries chapter 50 in VGCL/DRB (Vulgate)
 * and chapter 51 in TEST1 (Hebrew).
 */
class PsalmChapterSwapIntegrationTest extends DatabaseTestCase
{
    private function executePipeline(string $query, string $version): QuoteContext
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
     * Psalm51(50),3 with VGCL → should query chapter 50, return Latin "Miserere mei".
     */
    public function testPsalmDualNumberingVgclUsesAlternateChapter(): void
    {
        $ctx = $this->executePipeline('Psalm51(50),3', 'VGCL');

        self::assertEmpty($ctx->errors, 'Expected no errors: ' . print_r($ctx->errors, true));
        self::assertCount(1, $ctx->results);
        self::assertEquals(50, $ctx->results[0]['chapter']);
        self::assertEquals(3, $ctx->results[0]['verse']);
        self::assertStringContainsString('Miserere', $ctx->results[0]['text']);
    }

    /**
     * Psalm51(50),3 with DRB → should query chapter 50, return English "Have mercy".
     */
    public function testPsalmDualNumberingDrbUsesAlternateChapter(): void
    {
        $ctx = $this->executePipeline('Psalm51(50),3', 'DRB');

        self::assertEmpty($ctx->errors, 'Expected no errors: ' . print_r($ctx->errors, true));
        self::assertCount(1, $ctx->results);
        self::assertEquals(50, $ctx->results[0]['chapter']);
        self::assertStringContainsString('mercy', $ctx->results[0]['text']);
    }

    /**
     * Psalm51(50),1 with TEST1 → should query chapter 51 (Hebrew numbering, no swap).
     */
    public function testPsalmDualNumberingTest1UsesPrimaryChapter(): void
    {
        $ctx = $this->executePipeline('Psalm51(50),1', 'TEST1');

        self::assertEmpty($ctx->errors, 'Expected no errors: ' . print_r($ctx->errors, true));
        self::assertCount(1, $ctx->results);
        self::assertEquals(51, $ctx->results[0]['chapter']);
        self::assertEquals(1, $ctx->results[0]['verse']);
    }

    /**
     * Psalm51,1 (no alternate) with VGCL → no swap, queries chapter 51 as-is.
     * VGCL chapter 51 is actually Hebrew Psalm 52 ("Quid gloriaris").
     */
    public function testPsalmWithoutAlternateNoSwap(): void
    {
        $ctx = $this->executePipeline('Psalm51,1', 'VGCL');

        self::assertEmpty($ctx->errors, 'Expected no errors: ' . print_r($ctx->errors, true));
        self::assertCount(1, $ctx->results);
        self::assertEquals(51, $ctx->results[0]['chapter']);
        self::assertStringContainsString('Intellectus', $ctx->results[0]['text']);
    }

    /**
     * Psalm51(50),3-5 range with VGCL → should return VGCL chapter 50 verses 3-5.
     */
    public function testPsalmDualNumberingRangeWithVgcl(): void
    {
        $ctx = $this->executePipeline('Psalm51(50),3-5', 'VGCL');

        self::assertEmpty($ctx->errors, 'Expected no errors: ' . print_r($ctx->errors, true));
        self::assertCount(3, $ctx->results);
        self::assertEquals(50, $ctx->results[0]['chapter']);
        self::assertEquals(3, $ctx->results[0]['verse']);
        self::assertEquals(5, $ctx->results[2]['verse']);
    }

    /**
     * Multi-version: Psalm51(50),3 with VGCL,TEST1 → VGCL returns ch50 v3,
     * TEST1 returns ch51 v3.
     */
    public function testPsalmDualNumberingMultiVersion(): void
    {
        $ctx = $this->executePipeline('Psalm51(50),3', 'VGCL,TEST1');

        self::assertEmpty($ctx->errors, 'Expected no errors: ' . print_r($ctx->errors, true));
        self::assertCount(2, $ctx->results);

        // Results are ordered by version iteration: VGCL first, then TEST1
        $vgclResult  = null;
        $test1Result = null;
        foreach ($ctx->results as $r) {
            if ($r['version'] === 'VGCL') {
                $vgclResult = $r;
            } elseif ($r['version'] === 'TEST1') {
                $test1Result = $r;
            }
        }

        self::assertNotNull($vgclResult, 'VGCL result expected');
        self::assertNotNull($test1Result, 'TEST1 result expected');
        self::assertEquals(50, $vgclResult['chapter']);
        self::assertEquals(51, $test1Result['chapter']);
    }
}
