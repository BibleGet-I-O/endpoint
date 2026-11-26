<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

/**
 * The editions' introductions (book_introduction, migration 071): each book's, and the general ones.
 *
 * @phpstan-type Heading array{id: string, title: string, books: list<int>}
 * @phpstan-type Introduction array{id: string, title: string, books: list<int>, blocks: list<mixed>}
 */
class IntroductionRepository
{
    private const COLUMNS = 'id, title, array_to_json(books) AS books';

    public function __construct(private readonly \PDO $db)
    {
    }

    /**
     * The book's own introduction (one may serve two books), or null.
     *
     * @return Introduction|null
     */
    public function forBook(string $edition, int $book): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ', blocks FROM book_introduction
              WHERE edition = ? AND NOT general AND ? = ANY (books) ORDER BY ord LIMIT 1'
        );
        $stmt->execute([$edition, $book]);
        $row = Cast::row($stmt->fetch(\PDO::FETCH_ASSOC));
        return $row === null ? null : self::withBlocks($row);
    }

    /**
     * The general introductions that cover the book, in print order.
     *
     * @return list<Heading>
     */
    public function generalFor(string $edition, int $book): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM book_introduction WHERE edition = ? AND general AND ? = ANY (books) ORDER BY ord'
        );
        $stmt->execute([$edition, $book]);
        return array_map(self::heading(...), Cast::rows($stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * An introduction by its id, or null.
     *
     * @return Introduction|null
     */
    public function byId(string $edition, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ', blocks FROM book_introduction WHERE edition = ? AND id = ?');
        $stmt->execute([$edition, $id]);
        $row = Cast::row($stmt->fetch(\PDO::FETCH_ASSOC));
        return $row === null ? null : self::withBlocks($row);
    }

    /**
     * @param array<string, mixed> $r
     * @return Heading
     */
    private static function heading(array $r): array
    {
        $books = json_decode(Cast::string($r['books']), true);
        return [
            'id'    => Cast::string($r['id']),
            'title' => Cast::string($r['title']),
            'books' => array_map(Cast::int(...), is_array($books) ? array_values($books) : []),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return Introduction
     */
    private static function withBlocks(array $r): array
    {
        $blocks = json_decode(Cast::string($r['blocks']), true);
        return self::heading($r) + ['blocks' => is_array($blocks) ? array_values($blocks) : []];
    }
}
