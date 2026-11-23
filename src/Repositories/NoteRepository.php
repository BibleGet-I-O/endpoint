<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

/**
 * The editions' notes (migration 064): the anchors of a stretch of chapters, and the bodies and citations of the
 * notes kept. A note's public id is "{edition}:{source_id}", stable across re-imports (the serial id is not).
 *
 * @phpstan-type Anchor array{note_id: int, id: string, kind: string, marker: ?string, chapter: int, verse: int, verse_segment: ?string, text_tradition: ?string, printed_chapter: ?int, field: string, position: ?int}
 * @phpstan-type Ref array{start: array{book: int, chapter: int, verse: ?int, segment: ?string, printed_chapter: ?int}, end: ?array{book: int, chapter: int, verse: int}, span: array{0: int, 1: int}, edition?: string}
 * @phpstan-type Body array{kind: string, marker: ?string, label: ?string, title: ?string, body: string, refs: list<Ref>}
 */
class NoteRepository
{
    public function __construct(private readonly \PDO $db)
    {
    }

    /**
     * Every anchor of the edition's notes in chapters $chapterFrom-$chapterTo of $book (universal number).
     *
     * @return list<Anchor>
     */
    public function anchors(string $edition, int $book, int $chapterFrom, int $chapterTo): array
    {
        $stmt = $this->db->prepare(
            'SELECT n.id AS note_id, n.edition, n.source_id, n.kind, n.marker, a.chapter, a.verse, a.verse_segment,
                    a.text_tradition, a.printed_chapter, a.field, a.position
               FROM note_anchor a JOIN note n ON n.id = a.note_id
              WHERE n.edition = :e AND a.book = :b AND a.chapter BETWEEN :c1 AND :c2'
        );
        $stmt->execute(['e' => $edition, 'b' => $book, 'c1' => $chapterFrom, 'c2' => $chapterTo]);
        $out = [];
        foreach (Cast::rows($stmt->fetchAll()) as $r) {
            $out[] = [
                'note_id'         => Cast::int($r['note_id']),
                'id'              => Cast::string($r['edition']) . ':' . Cast::string($r['source_id']),
                'kind'            => Cast::string($r['kind']),
                'marker'          => self::str($r['marker']),
                'chapter'         => Cast::int($r['chapter']),
                'verse'           => Cast::int($r['verse']),
                'verse_segment'   => self::str($r['verse_segment']),
                'text_tradition'  => self::str($r['text_tradition']),
                'printed_chapter' => self::int($r['printed_chapter']),
                'field'           => Cast::string($r['field']),
                'position'        => self::int($r['position']),
            ];
        }
        return $out;
    }

    /**
     * The notes' bodies and citations (in printed order), keyed by the serial note id.
     *
     * @param list<int> $noteIds
     * @return array<int, Body>
     */
    public function bodies(array $noteIds): array
    {
        if ($noteIds === []) {
            return [];
        }
        $in    = '{' . implode(',', array_map('intval', $noteIds)) . '}';
        $notes = $this->db->prepare('SELECT id, kind, marker, label, title, body FROM note WHERE id = ANY(:ids::int[]) ORDER BY id');
        $notes->execute(['ids' => $in]);
        $out = [];
        foreach (Cast::rows($notes->fetchAll()) as $r) {
            $out[Cast::int($r['id'])] = [
                'kind'   => Cast::string($r['kind']),
                'marker' => self::str($r['marker']),
                'label'  => self::str($r['label']),
                'title'  => self::str($r['title']),
                'body'   => Cast::string($r['body']),
                'refs'   => [],
            ];
        }
        $refs = $this->db->prepare(
            'SELECT note_id, book, chapter, verse, verse_segment, printed_chapter, end_book, end_chapter, end_verse, span_start, span_end, edition
               FROM note_ref WHERE note_id = ANY(:ids::int[]) ORDER BY note_id, ord'
        );
        $refs->execute(['ids' => $in]);
        foreach (Cast::rows($refs->fetchAll()) as $r) {
            $id = Cast::int($r['note_id']);
            if (isset($out[$id])) {
                $out[$id]['refs'][] = self::ref($r);
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return Ref
     */
    private static function ref(array $r): array
    {
        $endBook = self::int($r['end_book']);
        $ref     = [
            'start' => [
                'book'            => Cast::int($r['book']),
                'chapter'         => Cast::int($r['chapter']),
                'verse'           => self::int($r['verse']),
                'segment'         => self::str($r['verse_segment']),
                'printed_chapter' => self::int($r['printed_chapter']),
            ],
            'end'   => $endBook === null ? null : ['book' => $endBook, 'chapter' => Cast::int($r['end_chapter']), 'verse' => Cast::int($r['end_verse'])],
            'span'  => [Cast::int($r['span_start']), Cast::int($r['span_end'])],
        ];
        // A citation into another edition says which (the Nova Vulgata's verses in CEI 2008's notes).
        $edition = self::str($r['edition'] ?? null);
        if ($edition !== null) {
            $ref['edition'] = $edition;
        }
        return $ref;
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : Cast::string($v);
    }

    private static function int(mixed $v): ?int
    {
        return $v === null ? null : Cast::int($v);
    }
}
