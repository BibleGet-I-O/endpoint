<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\QueryFormulator;
use BibleGet\Tests\Integration\DatabaseTestCase;

class QueryFormulatorIntegrationTest extends DatabaseTestCase
{
    private function formulateFor(string $query, string $version = 'TEST1'): QuoteContext
    {
        $ctx = new QuoteContext(['query' => $query, 'version' => $version]);
        $ctx->initialize();
        $ctx->queryStrClean();

        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        $formulator = new QueryFormulator($ctx);
        $formulator->formulateSQLQueries();

        return $ctx;
    }

    public function testFormulatesSingleVerse(): void
    {
        $ctx = $this->formulateFor('Genesis1,1');

        self::assertNotEmpty($ctx->formulatedQueries);
        self::assertCount(1, $ctx->formulatedQueries);
        self::assertStringContainsString('SELECT * FROM TEST1', $ctx->formulatedQueries[0]);
        self::assertStringContainsString('book = 1', $ctx->formulatedQueries[0]);
        self::assertStringContainsString('chapter = 1', $ctx->formulatedQueries[0]);
        self::assertStringContainsString('verse = 1', $ctx->formulatedQueries[0]);
        self::assertStringContainsString('ORDER BY verseID', $ctx->formulatedQueries[0]);
    }

    public function testFormulatesVerseRange(): void
    {
        $ctx = $this->formulateFor('Genesis1,1-3');

        self::assertNotEmpty($ctx->formulatedQueries);
        self::assertStringContainsString('verse >= 1', $ctx->formulatedQueries[0]);
        self::assertStringContainsString('verse <= 3', $ctx->formulatedQueries[0]);
    }

    public function testFormulatesWholeChapter(): void
    {
        $ctx = $this->formulateFor('Genesis1');

        self::assertNotEmpty($ctx->formulatedQueries);
        self::assertStringContainsString('chapter = 1', $ctx->formulatedQueries[0]);
        // No verse filter for whole chapter
        self::assertStringNotContainsString('verse =', $ctx->formulatedQueries[0]);
    }

    public function testFormulatesChapterRange(): void
    {
        $ctx = $this->formulateFor('Genesis1-2');

        self::assertNotEmpty($ctx->formulatedQueries);
        self::assertStringContainsString('chapter >= 1', $ctx->formulatedQueries[0]);
        self::assertStringContainsString('chapter <= 2', $ctx->formulatedQueries[0]);
    }

    public function testFormulatesDiscontinuousVerses(): void
    {
        $ctx = $this->formulateFor('Genesis1,1.3');

        // Should produce 2 separate queries (one per discontinuous chunk)
        self::assertCount(2, $ctx->formulatedQueries);
        self::assertStringContainsString('verse = 1', $ctx->formulatedQueries[0]);
        self::assertStringContainsString('verse = 3', $ctx->formulatedQueries[1]);
    }

    public function testFormulatesMultipleVersions(): void
    {
        $ctx = $this->formulateFor('Genesis1,1', 'TEST1,TEST2');

        // Should have queries for both versions
        self::assertGreaterThanOrEqual(2, count($ctx->formulatedQueries));

        $hasTest1 = false;
        $hasTest2 = false;
        foreach ($ctx->formulatedQueries as $q) {
            if (str_contains($q, 'FROM TEST1')) {
                $hasTest1 = true;
            }
            if (str_contains($q, 'FROM TEST2')) {
                $hasTest2 = true;
            }
        }
        self::assertTrue($hasTest1, 'Should have query for TEST1');
        self::assertTrue($hasTest2, 'Should have query for TEST2');
    }

    public function testFormulatedVariantsTrackVersionPerQuery(): void
    {
        $ctx = $this->formulateFor('Genesis1,1');

        self::assertNotEmpty($ctx->formulatedVariants);
        self::assertSame('TEST1', $ctx->formulatedVariants[0]);
    }

    public function testOriginalQueriesPreserved(): void
    {
        $ctx = $this->formulateFor('Genesis1,1');

        self::assertNotEmpty($ctx->originalQueries);
        self::assertSame('Genesis1,1', $ctx->originalQueries[0]);
    }

    public function testCopyrightVersionGetsLimit(): void
    {
        // TEST2 is a copyright version
        $ctx = $this->formulateFor('Genesis1', 'TEST2');

        self::assertNotEmpty($ctx->formulatedQueries);
        self::assertStringContainsString('LIMIT 30', $ctx->formulatedQueries[0]);
    }

    public function testNonCopyrightVersionNoLimit(): void
    {
        $ctx = $this->formulateFor('Genesis1', 'TEST1');

        self::assertNotEmpty($ctx->formulatedQueries);
        self::assertStringNotContainsString('LIMIT', $ctx->formulatedQueries[0]);
    }

    public function testCrossChapterRange(): void
    {
        $ctx = $this->formulateFor('Genesis1,5-2,3');

        self::assertNotEmpty($ctx->formulatedQueries);
        $q = $ctx->formulatedQueries[0];
        self::assertStringContainsString('chapter = 1', $q);
        self::assertStringContainsString('verse >= 5', $q);
        self::assertStringContainsString('chapter = 2', $q);
        self::assertStringContainsString('verse <= 3', $q);
    }
}
