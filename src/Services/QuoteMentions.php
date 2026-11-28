<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Repositories\MentionRepository;
use BibleGet\Api\Util\StringUtils;

/**
 * Mentions on /v3/quote (`mentions=1|true`, mentions-and-cards spec, Endpoint), in JSON only (other formats ignore
 * the parameter). Each row of an edition that has CBPPEDR mentions gets `mentions: [{start, end, entry} | {start,
 * end, candidates}]`, in code points of its formatted text, ordered by start and then outer before inner; a row is
 * matched by verse identity (NULLs equal), and gets none when its text_plain no longer hashes to the mentions'
 * plain_hash or its formatted text to their text_hash (the positions count the formatted text's code points, so a
 * change of markup alone moves them). `mentions` may also be a JSON body's `true` or `1`; any other value is off. The cards of exactly the entries the sent mentions name come back keyed by id, each with the number of
 * verses of its index entry in the edition of the first row that names it. Mentions follow the rows given, so a
 * visitor's 30 verses of a copyrighted edition carry only theirs.
 *
 * @phpstan-import-type MentionRow from MentionRepository
 * @phpstan-import-type Card from MentionRepository
 * @phpstan-type Mark array{start: int, end: int, entry?: string, candidates?: list<string>}
 */
final class QuoteMentions
{
    /**
     * @param array<string, mixed> $params
     */
    public static function requested(array $params): bool
    {
        $raw = $params['mentions'] ?? null;
        if ($raw === true || $raw === 1) { // a JSON body's native values
            return true;
        }
        return is_string($raw) && in_array(strtolower(trim($raw)), ['1', 'true'], true);
    }

    /**
     * Puts each row's mentions on it, and returns the cards of the entries they name, keyed by id; the mentions and
     * the cards are read in one snapshot (MentionRepository::inOneSnapshot).
     *
     * @param list<array<string, mixed>> $results
     * @return array{0: list<array<string, mixed>>, 1: array<string, Card>}
     */
    public static function attach(array $results, MentionRepository $repo): array
    {
        return $repo->inOneSnapshot(static fn (): array => self::attachIn($results, $repo));
    }

    /**
     * attach(), in the repository's snapshot.
     *
     * @param list<array<string, mixed>> $results
     * @return array{0: list<array<string, mixed>>, 1: array<string, Card>}
     */
    private static function attachIn(array $results, MentionRepository $repo): array
    {
        $editions = [];
        foreach ($results as $row) {
            $editions[StringUtils::asString($row['version'] ?? '')] = true;
        }
        $with = array_fill_keys($repo->editionsWithMentions(array_keys($editions)), true);
        if ($with === []) {
            return [$results, []];
        }
        $byKey = self::mentionsByKey($results, $with, $repo);
        $named = [];
        foreach ($results as $i => $row) {
            $edition = StringUtils::asString($row['version'] ?? '');
            if (!isset($with[$edition])) {
                continue;
            }
            $plainHash = hash('sha256', StringUtils::asString($row['text_plain'] ?? ''));
            $textHash  = hash('sha256', StringUtils::asString($row['text'] ?? ''));
            $marks     = [];
            foreach ($byKey[self::rowKey($edition, $row)] ?? [] as $m) {
                if (hash_equals($m['plain_hash'], $plainHash) && hash_equals($m['text_hash'], $textHash)) {
                    $marks[] = self::mark($m);
                    foreach ($m['candidates'] ?? [(string) $m['entry']] as $id) {
                        $named[$id] ??= $edition;
                    }
                }
            }
            usort($marks, static fn (array $a, array $b): int => [$a['start'], $b['end']] <=> [$b['start'], $a['end']]);
            $results[$i]['mentions'] = $marks;
        }
        return [$results, self::cards($named, $repo)];
    }

    /**
     * @param MentionRow $m
     * @return Mark
     */
    private static function mark(array $m): array
    {
        return $m['candidates'] !== null
            ? ['start' => $m['start'], 'end' => $m['end'], 'candidates' => $m['candidates']]
            : ['start' => $m['start'], 'end' => $m['end'], 'entry' => (string) $m['entry']];
    }

    /**
     * The cards of the entries named, each with the verse count of the edition it was first named in.
     *
     * @param array<string, string> $named edition by entry id
     * @return array<string, Card>
     */
    private static function cards(array $named, MentionRepository $repo): array
    {
        $byEdition = [];
        foreach ($named as $id => $edition) {
            $byEdition[$edition][] = (string) $id;
        }
        $cards = [];
        foreach ($byEdition as $edition => $ids) {
            sort($ids);
            $cards += $repo->cards((string) $edition, $ids);
        }
        return $cards;
    }

    /**
     * The mentions of the chapters the results span, one query per edition and book, keyed by verse identity.
     *
     * @param list<array<string, mixed>> $results
     * @param array<string, true>        $with    the editions that have mentions
     * @return array<string, list<MentionRow>>
     */
    private static function mentionsByKey(array $results, array $with, MentionRepository $repo): array
    {
        $spans = [];
        foreach ($results as $row) {
            $edition = StringUtils::asString($row['version'] ?? '');
            if (!isset($with[$edition])) {
                continue;
            }
            $book      = StringUtils::asInt($row['univbooknum'] ?? 0);
            $chapter   = StringUtils::asInt($row['chapter'] ?? 0);
            $k         = $edition . '|' . $book;
            $spans[$k] = isset($spans[$k])
                ? [$edition, $book, min($spans[$k][2], $chapter), max($spans[$k][3], $chapter)]
                : [$edition, $book, $chapter, $chapter];
        }
        $out = [];
        foreach ($spans as [$edition, $book, $from, $to]) {
            foreach ($repo->mentions($edition, $book, $from, $to) as $m) {
                $out[self::key($edition, $book, $m['chapter'], $m['verse'], $m['segment'], $m['tradition'])][] = $m;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowKey(string $edition, array $row): string
    {
        $opt = static fn (mixed $v): ?string => $v === null || $v === '' ? null : StringUtils::asString($v);
        return self::key(
            $edition,
            StringUtils::asInt($row['univbooknum'] ?? 0),
            StringUtils::asInt($row['chapter'] ?? 0),
            StringUtils::asInt($row['verse'] ?? 0),
            $opt($row['verse_segment'] ?? null),
            $opt($row['text_tradition'] ?? null)
        );
    }

    private static function key(string $edition, int $book, int $chapter, int $verse, ?string $segment, ?string $tradition): string
    {
        return $edition . '|' . $book . '|' . $chapter . '|' . $verse . '|' . ( $segment ?? '' ) . '|' . ( $tradition ?? '' );
    }
}
