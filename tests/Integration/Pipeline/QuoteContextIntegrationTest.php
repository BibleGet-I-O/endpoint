<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Tests\Integration\DatabaseTestCase;

class QuoteContextIntegrationTest extends DatabaseTestCase
{
    public function testInitializeLoadsVersions(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();

        self::assertContains('TEST1', $ctx->VALID_VERSIONS);
        self::assertContains('TEST2', $ctx->VALID_VERSIONS);
        self::assertArrayHasKey('TEST1', $ctx->VALID_VERSIONS_FULLNAME);
    }

    public function testInitializeLoadsCatholicAndProtestantVersions(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();

        self::assertContains('TEST1', $ctx->CATHOLIC_VERSIONS);
        self::assertContains('TEST2', $ctx->PROTESTANT_VERSIONS);
    }

    public function testInitializeLoadsCopyrightVersions(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();

        self::assertNotContains('TEST1', $ctx->COPYRIGHT_VERSIONS);
        self::assertContains('TEST2', $ctx->COPYRIGHT_VERSIONS);
    }

    public function testInitializeLoadsRequestedVersions(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();

        self::assertContains('TEST1', $ctx->REQUESTED_VERSIONS);
    }

    public function testInitializeLoadsMultipleRequestedVersions(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1,TEST2']);
        $ctx->initialize();

        self::assertContains('TEST1', $ctx->REQUESTED_VERSIONS);
        self::assertContains('TEST2', $ctx->REQUESTED_VERSIONS);
    }

    public function testInitializeRejectsInvalidVersion(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'INVALID']);

        // When no valid versions remain, initialize() throws ValidationException
        $this->expectException(\BibleGet\Api\Http\Exception\ValidationException::class);
        $this->expectExceptionMessage('No valid Bible versions were requested');
        $ctx->initialize();
    }

    public function testInitializeThrowsWhenDefaultVersionNotInFixture(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => '']);

        // Default version (CEI2008) is not in test fixture, so initialize()
        // throws after failing to find a valid version
        $this->expectException(\BibleGet\Api\Http\Exception\ValidationException::class);
        $ctx->initialize();
    }

    public function testInitializeLoadsBibleBooks(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();

        self::assertNotEmpty($ctx->BIBLEBOOKS);
        // Should have at least 3 books (Genesis, Exodus, Leviticus)
        self::assertGreaterThanOrEqual(3, count($ctx->BIBLEBOOKS));
    }

    public function testInitializeLoadsIndexes(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();

        self::assertArrayHasKey('TEST1', $ctx->INDEXES);
        self::assertArrayHasKey('abbreviations', $ctx->INDEXES['TEST1']);
        self::assertArrayHasKey('biblebooks', $ctx->INDEXES['TEST1']);
        self::assertArrayHasKey('chapter_limit', $ctx->INDEXES['TEST1']);
        self::assertArrayHasKey('verse_limit', $ctx->INDEXES['TEST1']);
        self::assertArrayHasKey('book_num', $ctx->INDEXES['TEST1']);
    }

    public function testInitializeIndexHasCorrectChapterLimits(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();

        // Genesis has 3 chapters in test data
        $genIdx = array_search(1, $ctx->INDEXES['TEST1']['book_num']);
        self::assertNotFalse($genIdx);
        self::assertSame(3, $ctx->INDEXES['TEST1']['chapter_limit'][$genIdx]);
    }

    public function testInitializeIndexHasCorrectVerseLimits(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();

        $genIdx = array_search(1, $ctx->INDEXES['TEST1']['book_num']);
        self::assertNotFalse($genIdx);
        // Genesis ch1 has 10 verses, ch2 has 8, ch3 has 5
        self::assertSame([10, 8, 5], $ctx->INDEXES['TEST1']['verse_limit'][$genIdx]);
    }

    public function testIncrementCounters(): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1,1', 'version' => 'TEST1']);
        $ctx->initialize();

        $ctx->incrementGoodQueryCount();
        $ctx->incrementGoodQueryCount();
        $ctx->incrementBadQueryCount();

        $result = $ctx->mysqli->query('SELECT good, bad FROM counter');
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertNotNull($row);
        self::assertSame('2', (string) $row['good']);
        self::assertSame('1', (string) $row['bad']);
    }
}
