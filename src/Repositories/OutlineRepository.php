<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

/**
 * The editions' titles with their levels (book_outline, migration 069).
 *
 * @phpstan-type Title array{level: int, title: string, chapter: int, verse: int, segment: ?string, printed_chapter: ?int}
 */
class OutlineRepository
{
    public function __construct(private readonly \PDO $db)
    {
    }

    public function editionExists(string $edition): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM versions_available WHERE sigla = ? AND type = 'BIBLE'");
        $stmt->execute([$edition]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * A book's titles in reading order; those of the edition's commentary (reader_only) only when $reader.
     *
     * @return list<Title>
     */
    public function titles(string $edition, int $book, bool $reader): array
    {
        $stmt = $this->db->prepare(
            'SELECT level, title, chapter, verse, verse_segment, printed_chapter FROM book_outline
              WHERE edition = ? AND book = ?' . ( $reader ? '' : ' AND NOT reader_only' ) . ' ORDER BY ord'
        );
        $stmt->execute([$edition, $book]);
        $out = [];
        foreach (Cast::rows($stmt->fetchAll(\PDO::FETCH_ASSOC)) as $r) {
            $out[] = [
                'level'           => Cast::int($r['level']),
                'title'           => Cast::string($r['title']),
                'chapter'         => Cast::int($r['chapter']),
                'verse'           => Cast::int($r['verse']),
                'segment'         => $r['verse_segment'] === null ? null : Cast::string($r['verse_segment']),
                'printed_chapter' => $r['printed_chapter'] === null ? null : Cast::int($r['printed_chapter']),
            ];
        }
        return $out;
    }
}
