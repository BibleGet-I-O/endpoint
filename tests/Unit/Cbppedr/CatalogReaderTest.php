<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Cbppedr;

use BibleGet\Api\Cbppedr\CatalogReader;
use PHPUnit\Framework\TestCase;

/** Reading a CBPPEDR checkout's three catalogs (a synthetic one under tests/fixtures/cbppedr). */
final class CatalogReaderTest extends TestCase
{
    public function testReadsEveryCatalogWithLabelsAndUncertainty(): void
    {
        $entries = ( new CatalogReader() )->read(__DIR__ . '/../../fixtures/cbppedr')['entries'];
        $byId    = array_column($entries, null, 'id');
        self::assertCount(6, $entries);
        self::assertSame(['btu:gn.1.1', 'btu:gn.1.2'], $byId['bpers:fixture.a']['attested']);
        self::assertSame(['btu:gn.1.2' => ['NVBSE', 'NABRE']], $byId['bpers:fixture.a']['labels']);
        self::assertSame('reviewed', $byId['bpers:fixture.a']['qid_status']);
        self::assertFalse($byId['bpers:fixture.a']['uncertain']);
        self::assertTrue($byId['bpers:fixture.b']['uncertain']);
        self::assertSame('event', $byId['bevent:eventus']['type']);
        self::assertSame('place', $byId['bplace:locus.b']['type']);
        self::assertSame(['la' => '', 'it' => 'Terzi', 'en' => 'Thirds'], $byId['bpers:fixture.c']['names']);
        self::assertNull($byId['bpers:fixture.c']['qid']);
    }

    public function testRefusesADirectoryWithoutTheCatalogs(): void
    {
        $this->expectException(\RuntimeException::class);
        ( new CatalogReader() )->read(__DIR__);
    }
}
