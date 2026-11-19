<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Transform;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Ast\VerseRange;
use BibleGet\Api\Pipeline\Ast\VerseRef;

/**
 * AST→AST transformation that remaps Esther chapter/verse numbers for
 * VGCL and DRB Catholic versions (Greek additions to Esther).
 *
 * Book 19 = Esther in the Catholic canon. Chapters 10–16 contain the Greek
 * additions which require text_tradition filtering.
 *
 * This is a pure function of the AST — no database access, no SQL.
 */
final class EstherRemapper
{
    private const ESTHER_BOOK_NUM = 19;

    /**
     * Editions that print Esther in the Vulgate pattern: the Hebrew chapters
     * 1-10, then the Greek additions as chapters 11-16 (index: 16 chapters).
     * Their chapters 1-10 also store the additions as lettered sub-verses
     * (verse_segment) of the GREEK rows, where the other editions print them.
     *
     * @var list<string>
     */
    public const SUPPLEMENTARY_VERSIONS = ['VGCL', 'DRB'];

    /**
     * The GREEK rows of chapters 1-10 that a supplementary edition does not
     * print there: the lettered sub-verses (it prints them as chapters 11-16).
     */
    private const NOT_LETTERED = ' AND verse_segment IS NULL';

    /**
     * Esther verse-mapping rules for VGCL/DRB versions (Greek additions).
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
     * Remap a BibleQuery AST if it targets Esther in a VGCL/DRB version.
     *
     * Returns a new BibleQuery with remapped segments, plus the text_tradition
     * annotation to use. If no remapping applies, returns the query unchanged.
     *
     * Returns per-segment textTradition strings so each non-consecutive chunk
     * gets the correct text_tradition filter (matching legacy per-chunk behavior).
     *
     * @return array{BibleQuery, array<int, string>} [remappedQuery, perSegmentTextTraditions]
     */
    public function remap(BibleQuery $query, string $version, string $defaultTextTradition): array
    {
        if (!$this->shouldRemap($query->book, $version)) {
            $origins = array_fill(0, count($query->segments), $defaultTextTradition);
            return [$query, $origins];
        }

        $segments = [];
        $origins  = [];

        foreach ($query->segments as $segment) {
            if ($segment instanceof VerseRef) {
                [$mapped, $po] = $this->remapVerseRef($segment, $defaultTextTradition);
                $segments[]    = $mapped;
                $origins[]     = $po;
            } elseif ($segment instanceof VerseRange) {
                [$from, $poFrom] = $this->remapVerseRef($segment->from, $defaultTextTradition);
                [$to ]           = $this->remapVerseRef($segment->to, $defaultTextTradition);
                $segments[]      = new VerseRange($from, $to);
                $origins[]       = $poFrom;
            }
        }

        return [new BibleQuery($query->book, $segments), $origins];
    }

    /**
     * numbering=edition: Esther as a supplementary edition prints it. Chapters
     * 11-16 are read as stored, not remapped; unless the client chose a
     * text_tradition, the lettered sub-verses are left out, so chapters 1-10
     * hold the edition's own verses (10:4-13 included: GREEK continuation
     * verses, not lettered). Any other book or edition is returned unchanged,
     * as remap() would for an edition that needs no remapping.
     *
     * @param bool $explicitTradition Whether the client asked for a text_tradition.
     * @return array{BibleQuery, array<int, string>} [query, perSegmentTextTraditions]
     */
    public function editionRemap(BibleQuery $query, string $version, string $defaultTextTradition, bool $explicitTradition): array
    {
        $textTradition = $defaultTextTradition;
        if ($this->shouldRemap($query->book, $version) && !$explicitTradition) {
            $textTradition .= self::NOT_LETTERED;
        }
        return [$query, array_fill(0, count($query->segments), $textTradition)];
    }

    private function shouldRemap(int $book, string $version): bool
    {
        return $book === self::ESTHER_BOOK_NUM
            && in_array($version, $this->catholicVersions, true)
            && in_array($version, self::SUPPLEMENTARY_VERSIONS, true);
    }

    /**
     * @return array{VerseRef, string}
     */
    private function remapVerseRef(VerseRef $ref, string $defaultTextTradition): array
    {
        $mapped = $this->matchMapping($ref->chapter, $ref->verse);
        if ($mapped !== null) {
            [$chapter, $verse, $textTradition] = $mapped;
            return [
                new VerseRef($ref->book, $chapter, $ref->alternateChapter, $verse, $ref->partialSuffix),
                $textTradition,
            ];
        }
        return [$ref, $defaultTextTradition];
    }

    /**
     * @return array{int, int|null, string}|null [chapter, verse, text_tradition] or null
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
                    return [$mapping['map'][0], $mapping['map'][1], " AND text_tradition = 'GREEK'"];
                }
            }
        }
        return null;
    }
}
