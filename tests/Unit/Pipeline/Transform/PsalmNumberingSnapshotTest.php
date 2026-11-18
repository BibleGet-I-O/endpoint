<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline\Transform;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRef;
use BibleGet\Api\Pipeline\Transform\PsalmNumbering;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #210: every verse of the Hebrew Psalter, as the Nova Vulgata numbers it,
 * resolved for VGCL and DRB, must be a verse that edition has, according to the
 * verse snapshots in tests/fixtures/versification.
 *
 * The few that are not are versification differences outside the psalms the two
 * numberings split or join (the Clementine Ps 15 has 10 verses where Hebrew 16
 * has 11, and so on). PsalmNumbering does not map those, and they are listed here
 * so that a change to the mapping, or to the data, shows up.
 */
final class PsalmNumberingSnapshotTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../../fixtures/versification/';

    /**
     * Verse numbers of the Psalms in an edition's snapshot, as [chapter => [verse => true]].
     *
     * @return array<int, array<int, true>>
     */
    private static function psalmVerses(string $edition): array
    {
        $lines = file(self::DIR . $edition . '.txt', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $verses = [];
        foreach ($lines as $line) {
            if (preg_match('/^23 (\d+)(?: \w+)?: (.*)$/', $line, $m) !== 1) {
                continue;
            }
            foreach (explode(',', $m[2]) as $run) {
                if (preg_match('/^(\d+)-(\d+)$/', $run, $r) === 1) {
                    for ($v = (int) $r[1]; $v <= (int) $r[2]; $v++) {
                        $verses[(int) $m[1]][$v] = true;
                    }
                } elseif (preg_match('/^(\d+)/', $run, $r) === 1) {
                    $verses[(int) $m[1]][(int) $r[1]] = true;
                }
            }
        }
        return $verses;
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function editions(): array
    {
        return [
            'VGCL' => ['VGCL', ['16:11 → 15:11', '56:14 → 55:14', '94:24 → 93:24']],
            'DRB'  => ['DRB', ['20:10 → 19:10', '29:11 → 28:11', '56:14 → 55:14', '94:24 → 93:24']],
        ];
    }

    /**
     * @param list<string> $knownDifferences Hebrew verse → edition verse, for verses the edition lacks.
     */
    #[DataProvider('editions')]
    public function testEveryHebrewVerseResolvesToAVerseTheEditionHas(string $edition, array $knownDifferences): void
    {
        $hebrew    = self::psalmVerses('NVBSE');
        $has       = self::psalmVerses($edition);
        $numbering = new PsalmNumbering();

        self::assertCount(150, $hebrew);
        $missing = [];
        foreach ($hebrew as $chapter => $verses) {
            foreach (array_keys($verses) as $verse) {
                $ref = $numbering->resolve(new BibleQuery(23, [new VerseRef(23, $chapter, null, $verse)]), $edition)->segments[0];
                self::assertInstanceOf(VerseRef::class, $ref);
                if (!isset($has[$ref->chapter][(int) $ref->verse])) {
                    $missing[] = "{$chapter}:{$verse} → {$ref->chapter}:{$ref->verse}";
                }
            }
        }

        self::assertSame($knownDifferences, $missing);
    }
}
