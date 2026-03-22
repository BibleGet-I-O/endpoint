<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Transform;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;

/**
 * AST→AST transformation that remaps Psalm chapter/verse numbers for
 * VGCL and DRB Catholic versions (Greek/Vulgate numbering).
 *
 * This is a pure function of the AST — no database access, no SQL.
 * The mapping table is extracted from the legacy QueryFormulator::PSALM_VGCL_DRB_MAPPINGS.
 */
final class PsalmRemapper
{
    private const PSALMS_BOOK_NUM = 19;

    /**
     * Psalm verse-mapping rules for VGCL/DRB versions.
     * Each entry: [ranges => [[chapter, verseMin, verseMax], ...], map => [mappedChapter, mappedVerse]]
     * A null verseMin/verseMax means the rule matches when verse is null too.
     *
     * @var array<int, array{ranges: array<int, array{int, int|null, int|null}>, map: array{int, int}}>
     */
    private const MAPPINGS = [
        ['ranges' => [[10, 4, 13], [11, 1, 1]],  'map' => [10, 3]],
        ['ranges' => [[11, 2, 12], [12, 1, 6]],   'map' => [1, 1]],
        ['ranges' => [[13, 1, 7]],                 'map' => [3, 13]],
        ['ranges' => [[13, 8, 18], [14, 1, 19]],   'map' => [4, 17]],
        ['ranges' => [[15, 1, 3]],                 'map' => [4, 8]],
        ['ranges' => [[15, 4, 14]],                'map' => [5, 1]],
        ['ranges' => [[15, 15, 19]],               'map' => [5, 2]],
        ['ranges' => [[16, null, null]],           'map' => [8, 12]],
    ];

    /** @var array<int, string> */
    private array $catholicVersions;

    /**
     * @param array<int, string> $catholicVersions
     */
    public function __construct(array $catholicVersions)
    {
        $this->catholicVersions = $catholicVersions;
    }

    /**
     * Remap a BibleQuery AST if it targets Psalms in a VGCL/DRB version.
     *
     * Returns a new BibleQuery with remapped segments, plus the preferorigin
     * annotation to use. If no remapping applies, returns the query unchanged.
     *
     * @return array{BibleQuery, string} [remappedQuery, preferOriginSuffix]
     */
    public function remap(BibleQuery $query, string $version, string $defaultPreferOrigin): array
    {
        if (!$this->shouldRemap($query->book, $version)) {
            return [$query, $defaultPreferOrigin];
        }

        $segments     = [];
        $preferOrigin = $defaultPreferOrigin;

        foreach ($query->segments as $segment) {
            if ($segment instanceof VerseRef) {
                [$mapped, $po] = $this->remapVerseRef($segment, $defaultPreferOrigin);
                $segments[]    = $mapped;
                $preferOrigin  = $po;
            } elseif ($segment instanceof VerseRange) {
                [$from, $poFrom] = $this->remapVerseRef($segment->from, $defaultPreferOrigin);
                [$to, $poTo]     = $this->remapVerseRef($segment->to, $defaultPreferOrigin);
                $segments[]      = new VerseRange($from, $to);
                $preferOrigin    = $poFrom;
            }
        }

        return [new BibleQuery($query->book, $segments), $preferOrigin];
    }

    private function shouldRemap(int $book, string $version): bool
    {
        return $book === self::PSALMS_BOOK_NUM
            && in_array($version, $this->catholicVersions)
            && ( $version === 'VGCL' || $version === 'DRB' );
    }

    /**
     * @return array{VerseRef, string}
     */
    private function remapVerseRef(VerseRef $ref, string $defaultPreferOrigin): array
    {
        $mapped = $this->matchMapping($ref->chapter, $ref->verse);
        if ($mapped !== null) {
            [$chapter, $verse, $preferOrigin] = $mapped;
            return [
                new VerseRef($ref->book, $chapter, $ref->alternateChapter, $verse, $ref->partialSuffix),
                $preferOrigin,
            ];
        }
        return [$ref, $defaultPreferOrigin];
    }

    /**
     * @return array{int, int|null, string}|null [chapter, verse, preferorigin] or null
     */
    private function matchMapping(int $chapter, ?int $verse): ?array
    {
        foreach (self::MAPPINGS as $mapping) {
            foreach ($mapping['ranges'] as [$rngChapter, $rngMin, $rngMax]) {
                if ($chapter !== $rngChapter) {
                    continue;
                }
                $verseInRange = $rngMin === null
                    ? $verse === null
                    : $verse !== null && $verse >= $rngMin && $verse <= ( $rngMax ?? $rngMin );
                if ($verseInRange) {
                    return [$mapping['map'][0], $mapping['map'][1], " AND verseorigin = 'GREEK'"];
                }
            }
        }
        return null;
    }
}
