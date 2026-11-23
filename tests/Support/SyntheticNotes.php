<?php

declare(strict_types=1);

namespace BibleGet\Tests\Support;

/**
 * Synthetic notes for the tests (no edition's real notes are in this repository): a note, its anchors and its
 * citations, inserted as a data half would.
 */
final class SyntheticNotes
{
    /**
     * @param list<array{0:int,1:int,2:int,3?:?string,4?:?string,5?:?int,6?:string,7?:?int}> $anchors
     *        book, chapter, verse, verse_segment, text_tradition, printed_chapter, field (default text), position
     * @param list<array{0:int,1:int,2:?int,3:int,4:int,5?:?int,6?:?int,7?:?int}> $refs
     *        book, chapter, verse, span_start, span_end, end_book, end_chapter, end_verse
     */
    public static function add(
        \PDO $pdo,
        string $edition,
        string $sourceId,
        string $kind,
        ?string $marker,
        array $anchors,
        array $refs = [],
        string $body = 'Synthetic body.',
        ?string $label = null,
        ?string $title = null
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO note (edition, kind, marker, label, title, body, source_id) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$edition, $kind, $marker, $label, $title, $body, $sourceId]);
        $id = (int) $stmt->fetchColumn();
        $a  = $pdo->prepare(
            'INSERT INTO note_anchor (note_id, book, chapter, verse, verse_segment, text_tradition, printed_chapter, field, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($anchors as $x) {
            $a->execute([$id, $x[0], $x[1], $x[2], $x[3] ?? null, $x[4] ?? null, $x[5] ?? null, $x[6] ?? 'text', $x[7] ?? null]);
        }
        $r = $pdo->prepare(
            'INSERT INTO note_ref (note_id, ord, book, chapter, verse, span_start, span_end, end_book, end_chapter, end_verse)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($refs as $ord => $x) {
            $r->execute([$id, $ord + 1, $x[0], $x[1], $x[2], $x[3], $x[4], $x[5] ?? null, $x[6] ?? null, $x[7] ?? null]);
        }
        return $id;
    }
}
