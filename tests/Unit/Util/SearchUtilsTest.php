<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Util\SearchUtils;
use PHPUnit\Framework\TestCase;

final class SearchUtilsTest extends TestCase
{
    // ── parseVersionsParam ─────────────────────────────────────────

    public function testParseVersionsParamSingle(): void
    {
        $this->assertSame(['NABRE'], SearchUtils::parseVersionsParam('NABRE'));
    }

    public function testParseVersionsParamCommaSeparated(): void
    {
        $this->assertSame(
            ['NABRE', 'CEI2008', 'NVBSE'],
            SearchUtils::parseVersionsParam('NABRE,CEI2008,NVBSE')
        );
    }

    public function testParseVersionsParamUppercases(): void
    {
        $this->assertSame(['NABRE', 'CEI2008'], SearchUtils::parseVersionsParam('nabre, cei2008'));
    }

    public function testParseVersionsParamTrimsWhitespace(): void
    {
        $this->assertSame(['NABRE', 'CEI2008'], SearchUtils::parseVersionsParam(' NABRE , CEI2008 '));
    }

    public function testParseVersionsParamDeduplicatesPreservingOrder(): void
    {
        $this->assertSame(
            ['NABRE', 'CEI2008'],
            SearchUtils::parseVersionsParam('NABRE,CEI2008,NABRE,nabre')
        );
    }

    public function testParseVersionsParamEmptyStringThrows(): void
    {
        $this->expectException(ValidationException::class);
        SearchUtils::parseVersionsParam('');
    }

    public function testParseVersionsParamWhitespaceOnlyThrows(): void
    {
        $this->expectException(ValidationException::class);
        SearchUtils::parseVersionsParam('   ');
    }

    public function testParseVersionsParamCommasOnlyThrows(): void
    {
        $this->expectException(ValidationException::class);
        SearchUtils::parseVersionsParam(', , ,');
    }

    public function testParseVersionsParamNonStringThrows(): void
    {
        $this->expectException(ValidationException::class);
        SearchUtils::parseVersionsParam(null);
    }

    // ── assignCanonicalOrder ───────────────────────────────────────

    public function testAssignCanonicalOrderSingleVersionInOrder(): void
    {
        $rows = [
            ['version' => 'NABRE', 'verseID' => 100, 'text' => 'a'],
            ['version' => 'NABRE', 'verseID' => 200, 'text' => 'b'],
            ['version' => 'NABRE', 'verseID' => 300, 'text' => 'c'],
        ];
        SearchUtils::assignCanonicalOrder($rows);

        $this->assertSame(1, $rows[0]['canonical_order']);
        $this->assertSame(2, $rows[1]['canonical_order']);
        $this->assertSame(3, $rows[2]['canonical_order']);
        $this->assertArrayNotHasKey('verseID', $rows[0]);
        $this->assertArrayNotHasKey('verseID', $rows[1]);
        $this->assertArrayNotHasKey('verseID', $rows[2]);
    }

    public function testAssignCanonicalOrderRanksByVerseIDNotArrayPosition(): void
    {
        // Rows arrived in similarity-descending order; canonical_order must
        // still rank by the underlying verseID.
        $rows = [
            ['version' => 'NABRE', 'verseID' => 300, 'similarity' => 0.99],
            ['version' => 'NABRE', 'verseID' => 100, 'similarity' => 0.85],
            ['version' => 'NABRE', 'verseID' => 200, 'similarity' => 0.72],
        ];
        SearchUtils::assignCanonicalOrder($rows);

        // Output array order is preserved (still similarity-desc)…
        $this->assertSame(0.99, $rows[0]['similarity']);
        $this->assertSame(0.85, $rows[1]['similarity']);
        $this->assertSame(0.72, $rows[2]['similarity']);
        // …but canonical_order tracks verseID rank.
        $this->assertSame(3, $rows[0]['canonical_order']); // verseID 300 → rank 3
        $this->assertSame(1, $rows[1]['canonical_order']); // verseID 100 → rank 1
        $this->assertSame(2, $rows[2]['canonical_order']); // verseID 200 → rank 2
    }

    public function testAssignCanonicalOrderPerVersionPartition(): void
    {
        $rows = [
            ['version' => 'NABRE',   'verseID' => 50],
            ['version' => 'CEI2008', 'verseID' => 9000],
            ['version' => 'NABRE',   'verseID' => 70],
            ['version' => 'CEI2008', 'verseID' => 9001],
            ['version' => 'NABRE',   'verseID' => 60],
        ];
        SearchUtils::assignCanonicalOrder($rows);

        // NABRE: verseIDs 50, 70, 60 → ranks 1, 3, 2
        $this->assertSame(1, $rows[0]['canonical_order']);
        $this->assertSame(3, $rows[2]['canonical_order']);
        $this->assertSame(2, $rows[4]['canonical_order']);

        // CEI2008 partition restarts at 1
        $this->assertSame(1, $rows[1]['canonical_order']);
        $this->assertSame(2, $rows[3]['canonical_order']);
    }

    public function testAssignCanonicalOrderHandlesMissingVerseID(): void
    {
        $rows = [
            ['version' => 'NABRE'],
            ['version' => 'NABRE', 'verseID' => 100],
        ];
        SearchUtils::assignCanonicalOrder($rows);

        // Missing verseID coerces to 0; the row sorts before verseID 100.
        $this->assertSame(1, $rows[0]['canonical_order']);
        $this->assertSame(2, $rows[1]['canonical_order']);
    }

    public function testAssignCanonicalOrderEmptyArray(): void
    {
        $rows = [];
        SearchUtils::assignCanonicalOrder($rows);
        $this->assertSame([], $rows);
    }

    public function testAssignCanonicalOrderTiedVerseIDsAreDeterministic(): void
    {
        // Two rows in the same version with identical verseIDs (a degenerate
        // case in production, but worth pinning down). PHP's asort is stable
        // since 8.0, so the row that appeared first in $rows must end up
        // ranked first; the second tied row gets the next rank.
        $rows = [
            ['version' => 'NABRE', 'verseID' => 100, 'text' => 'first'],
            ['version' => 'NABRE', 'verseID' => 100, 'text' => 'second'],
            ['version' => 'NABRE', 'verseID' => 200, 'text' => 'third'],
        ];
        SearchUtils::assignCanonicalOrder($rows);

        // Both 100s rank ahead of 200 (correct), and the input order between
        // the two 100s is preserved → ranks 1 and 2 respectively.
        $this->assertSame(1, $rows[0]['canonical_order']);
        $this->assertSame(2, $rows[1]['canonical_order']);
        $this->assertSame(3, $rows[2]['canonical_order']);
        $this->assertSame('first', $rows[0]['text']);
        $this->assertSame('second', $rows[1]['text']);
    }
}
