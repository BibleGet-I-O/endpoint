<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Api\Http\Exception\ForbiddenException;
use BibleGet\Api\Repositories\NoteRepository;
use BibleGet\Api\Util\StringUtils;

/**
 * Notes on /v3/quote (`footnotes=none|markers|full`, footnotes spec Part 3). Only the BibleGet website's reader key
 * gets them, in JSON. A row gets the notes anchored to it, matched by verse identity (as `<T>_identity`, NULLs
 * equal): `markers` puts `notes: [{id, kind, marker, field, position}]` on each row; `full` also returns the
 * bodies once each, keyed by id. A note comes with the verse where it begins.
 *
 * @phpstan-import-type Anchor from NoteRepository
 * @phpstan-import-type Body from NoteRepository
 * @phpstan-type Marker array{id: string, kind: string, marker: ?string, field: string, position: ?int}
 * @phpstan-type FullNote array{kind: string, marker: ?string, label: ?string, title: ?string, body: string, refs: list<mixed>}
 */
final class QuoteNotes
{
    public const string NONE    = 'none';
    public const string MARKERS = 'markers';
    public const string FULL    = 'full';

    /** Display order of a verse's fields: chapter heading, headings, then the text. */
    private const array FIELD_ORDER = ['chapter' => 0, 'title1' => 1, 'title2' => 2, 'title3' => 3, 'text' => 4];

    /**
     * @param array<string, mixed> $params
     */
    public static function mode(array $params): string
    {
        $raw = $params['footnotes'] ?? self::NONE;
        $v   = is_string($raw) ? strtolower(trim($raw)) : null;
        if ($v === '') {
            return self::NONE;
        }
        if ($v === null || !in_array($v, [self::NONE, self::MARKERS, self::FULL], true)) {
            throw new BadRequestException('footnotes must be one of none, markers, full.');
        }
        return $v;
    }

    /** Refuses notes to anything but the reader key (403), and in a format other than JSON (400). */
    public static function guard(string $mode, ?Credential $credential, string $contentType): void
    {
        if ($mode === self::NONE) {
            return;
        }
        if ($credential?->scope !== 'reader') {
            throw new ForbiddenException("Notes are available only with the BibleGet website's reader key.");
        }
        if ($contentType !== 'application/json') {
            throw new BadRequestException('Notes are available in JSON only: ask for return=json, or leave footnotes out.');
        }
    }

    /**
     * Puts each row's markers on it and, for `full`, returns the notes' bodies keyed by id (null otherwise).
     *
     * @param list<array<string, mixed>> $results
     * @return array{0: list<array<string, mixed>>, 1: ?array<string, FullNote>}
     */
    public static function attach(array $results, string $mode, NoteRepository $repo): array
    {
        if ($mode === self::NONE) {
            return [$results, null];
        }
        $byKey = self::anchorsByKey($results, $repo);
        $used  = [];
        foreach ($results as $i => $row) {
            $edition = StringUtils::asString($row['version'] ?? '');
            $found   = $byKey[self::rowKey($edition, $row)] ?? [];
            usort($found, self::compare(...));
            $markers = [];
            foreach ($found as $a) {
                $markers[]           = ['id' => $a['id'], 'kind' => $a['kind'], 'marker' => $a['marker'], 'field' => $a['field'], 'position' => $a['position']];
                $used[$a['note_id']] = $a['id'];
            }
            $results[$i]['notes'] = $markers;
        }
        if ($mode !== self::FULL) {
            return [$results, null];
        }
        $full = [];
        foreach ($repo->bodies(array_keys($used)) as $noteId => $body) {
            $full[$used[$noteId]] = $body;
        }
        return [$results, $full];
    }

    /**
     * The anchors of the chapters the results span, per edition and book, keyed by verse identity.
     *
     * @param list<array<string, mixed>> $results
     * @return array<string, list<Anchor>>
     */
    private static function anchorsByKey(array $results, NoteRepository $repo): array
    {
        $spans = [];
        foreach ($results as $row) {
            $edition   = StringUtils::asString($row['version'] ?? '');
            $book      = StringUtils::asInt($row['univbooknum'] ?? 0);
            $chapter   = StringUtils::asInt($row['chapter'] ?? 0);
            $k         = $edition . '|' . $book;
            $spans[$k] = isset($spans[$k])
                ? [$edition, $book, min($spans[$k][2], $chapter), max($spans[$k][3], $chapter)]
                : [$edition, $book, $chapter, $chapter];
        }
        $out = [];
        foreach ($spans as [$edition, $book, $from, $to]) {
            foreach ($repo->anchors($edition, $book, $from, $to) as $a) {
                $key         = self::key($edition, $book, $a['chapter'], $a['verse'], $a['verse_segment'], $a['text_tradition'], $a['printed_chapter']);
                $out[$key][] = $a;
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
        $pc  = $row['printed_chapter'] ?? null;
        return self::key(
            $edition,
            StringUtils::asInt($row['univbooknum'] ?? 0),
            StringUtils::asInt($row['chapter'] ?? 0),
            StringUtils::asInt($row['verse'] ?? 0),
            $opt($row['verse_segment'] ?? null),
            $opt($row['text_tradition'] ?? null),
            $pc === null ? null : StringUtils::asInt($pc)
        );
    }

    private static function key(string $edition, int $book, int $chapter, int $verse, ?string $segment, ?string $tradition, ?int $printedChapter): string
    {
        return implode('|', [$edition, $book, $chapter, $verse, $segment ?? '-', $tradition ?? '-', $printedChapter ?? '-']);
    }

    /**
     * By field (display order), then position (the verse as a whole first), then marker.
     *
     * @param Anchor $a
     * @param Anchor $b
     */
    private static function compare(array $a, array $b): int
    {
        return [self::FIELD_ORDER[$a['field']] ?? 9, $a['position'] ?? -1, $a['marker'] ?? '']
            <=> [self::FIELD_ORDER[$b['field']] ?? 9, $b['position'] ?? -1, $b['marker'] ?? ''];
    }
}
