<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Cbppedr;

use BibleGet\Api\Cbppedr\CatalogReader;
use PHPUnit\Framework\TestCase;

/** Reading a CBPPEDR checkout's three catalogs (a synthetic one under tests/fixtures/cbppedr). */
final class CatalogReaderTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../fixtures/cbppedr';

    public function testReadsEveryCatalogWithLabelsAndUncertainty(): void
    {
        $entries = ( new CatalogReader() )->read(self::FIXTURE)['entries'];
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

    /** Each unit of the curated file replaces the generated one; a curated unit with no mentions removes it. */
    public function testReadsCuratedOverGenerated(): void
    {
        $catalog = ( new CatalogReader() )->read(self::FIXTURE);
        self::assertSame(['TEST1'], array_keys($catalog['mentions']));
        $units = $catalog['mentions']['TEST1'];
        self::assertSame(['btu:gn.1.1', 'btu:gn.1.2', 'btu:gn.1.4@GREEK'], array_keys($units));
        self::assertSame(
            [['start' => 0, 'end' => 7, 'check' => '0a1b2c3d', 'entry' => 'bpers:fixture.a', 'candidates' => null]],
            $units['btu:gn.1.1']['mentions']
        );
        self::assertSame(
            [['start' => 10, 'end' => 16, 'check' => '3a4b5c6d', 'entry' => null, 'candidates' => ['bpers:fixture.b']]],
            $units['btu:gn.1.2']['mentions']
        );
        self::assertSame(hash('sha256', 'b'), $units['btu:gn.1.2']['hash']);
        self::assertSame('bplace:locus.a', $units['btu:gn.1.4@GREEK']['mentions'][0]['entry']);
        self::assertSame(['bpers:fixture.a', 'bplace:locus.b'], array_keys($catalog['details']));
        self::assertSame(['la' => 'Fixtura', 'en' => 'Fixture'], $catalog['details']['bpers:fixture.a']['wikipedia']);
        self::assertSame('CC0', $catalog['details']['bpers:fixture.a']['image']['license'] ?? null);
        self::assertNull($catalog['details']['bpers:fixture.a']['coords']);
        self::assertSame(['lat' => 31.5, 'lon' => 35.25], $catalog['details']['bplace:locus.b']['coords']);
    }

    /** The units of settled mentions no source cites. */
    public function testReadsMentionedUnits(): void
    {
        $byId = array_column(( new CatalogReader() )->read(self::FIXTURE)['entries'], null, 'id');
        self::assertSame(['btu:gn.1.4', 'btu:gn.1.1'], $byId['bpers:fixture.a']['mentioned']);
        self::assertSame([], $byId['bpers:fixture.b']['mentioned']);
    }

    /** A checkout without mentions or details (details.json comes later) reads as none. */
    public function testReadsNoMentionsNorDetailsWhereThereAreNone(): void
    {
        $dir = sys_get_temp_dir() . '/cbppedr-' . bin2hex(random_bytes(4));
        mkdir($dir . '/data', 0o777, true);
        foreach (['persons', 'places', 'events'] as $c) {
            copy(self::FIXTURE . "/data/$c.json", "$dir/data/$c.json");
        }
        try {
            $catalog = ( new CatalogReader() )->read($dir);
            self::assertSame([], $catalog['mentions']);
            self::assertSame([], $catalog['details']);
        } finally {
            array_map('unlink', glob("$dir/data/*.json") ?: []);
            rmdir("$dir/data");
            rmdir($dir);
        }
    }

    /** What a mentions file holds that is not a unit or a mention is skipped, and counted by file. */
    public function testCountsWhatItSkipsAsMalformed(): void
    {
        self::assertSame([], ( new CatalogReader() )->read(self::FIXTURE)['malformed']);

        $dir = sys_get_temp_dir() . '/cbppedr-' . bin2hex(random_bytes(4));
        mkdir($dir . '/data/mentions', 0o777, true);
        foreach (['persons', 'places', 'events'] as $c) {
            copy(self::FIXTURE . "/data/$c.json", "$dir/data/$c.json");
        }
        $ok = ['start' => 0, 'end' => 1, 'check' => '0a1b2c3d', 'entry' => 'bpers:fixture.a'];
        file_put_contents("$dir/data/mentions/TEST1.json", json_encode([
            'edition' => 'TEST1',
            'units'   => [
                'btu:gn.1.1' => ['hash' => 'aa', 'mentions' => [$ok]],
            ]
        ]));
        file_put_contents("$dir/data/mentions/TEST1_curated.json", json_encode([
            'edition' => 'TEST1',
            'units'   => [
                'btu:gn.1.2' => ['mentions' => [$ok]],                     // no hash
                'btu:gn.1.3' => 'not a unit',
                'btu:gn.1.4' => [
                    'hash'     => 'bb',
                    'mentions' => [
                        $ok,
                        ['start' => '0', 'end' => 1, 'check' => '0a1b2c3d', 'entry' => 'bpers:fixture.a'], // start not an int
                        ['start' => 0, 'end' => 1, 'check' => '0a1b2c3d', 'entry' => 'bpers:fixture.a', 'candidates' => ['bpers:fixture.b']], // both
                        ['start' => 0, 'end' => 1, 'check' => '0a1b2c3d'],     // neither
                    ]
                ],
            ]
        ]));
        try {
            $catalog = ( new CatalogReader() )->read($dir);
            self::assertSame(['TEST1_curated.json' => ['units' => 2, 'mentions' => 3]], $catalog['malformed']);
            self::assertSame(['btu:gn.1.1', 'btu:gn.1.4'], array_keys($catalog['mentions']['TEST1']));
            self::assertCount(1, $catalog['mentions']['TEST1']['btu:gn.1.4']['mentions']);
        } finally {
            array_map('unlink', glob("$dir/data/mentions/*.json") ?: []);
            array_map('unlink', glob("$dir/data/*.json") ?: []);
            rmdir("$dir/data/mentions");
            rmdir("$dir/data");
            rmdir($dir);
        }
    }

    public function testRefusesADirectoryWithoutTheCatalogs(): void
    {
        $this->expectException(\RuntimeException::class);
        ( new CatalogReader() )->read(__DIR__);
    }
}
