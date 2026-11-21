<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Services\CopyrightPolicy;
use BibleGet\Api\Util\SearchUtils;
use PHPUnit\Framework\TestCase;

final class SearchUtilsCapTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function rows(string $version, int $n): array
    {
        return array_map(static fn (int $i): array => ['version' => $version, 'verse' => $i], range(1, $n));
    }

    private static function policy(): CopyrightPolicy
    {
        return new CopyrightPolicy(['TEST2'], ['TEST2']);
    }

    public function testKeepsTheFirstThirtyOfACappedEdition(): void
    {
        $out = SearchUtils::applyCopyrightCap(self::rows('TEST2', 35), self::policy(), null);
        self::assertCount(30, $out['rows']);
        self::assertSame(range(1, 30), array_column($out['rows'], 'verse'));
        self::assertSame(['TEST2' => 35], $out['capped']);
    }

    public function testThirtyExactlyIsNotReportedAsCapped(): void
    {
        $out = SearchUtils::applyCopyrightCap(self::rows('TEST2', 30), self::policy(), null);
        self::assertCount(30, $out['rows']);
        self::assertSame([], $out['capped']);
    }

    public function testReaderFullTextIsNotCapped(): void
    {
        $reader = new Credential(CredentialType::Project, 'k1', 'reader', null, 'a1', null, null, true);
        $out    = SearchUtils::applyCopyrightCap(self::rows('TEST2', 35), self::policy(), $reader);
        self::assertCount(35, $out['rows']);
        self::assertSame([], $out['capped']);
    }

    public function testOnlyTheCappedEditionLosesRowsAndTheOrderIsKept(): void
    {
        $rows = [];
        foreach (range(1, 40) as $i) {
            $rows[] = ['version' => $i % 2 === 0 ? 'TEST2' : 'TEST1', 'verse' => $i];
        }
        $out = SearchUtils::applyCopyrightCap($rows, self::policy(), null);
        self::assertCount(20, array_filter($out['rows'], static fn (array $r): bool => $r['version'] === 'TEST1'));
        self::assertCount(20, array_filter($out['rows'], static fn (array $r): bool => $r['version'] === 'TEST2'));
        self::assertSame([], $out['capped']);
        $verses = array_column($out['rows'], 'verse');
        $sorted = $verses;
        sort($sorted);
        self::assertSame($sorted, $verses);
    }
}
