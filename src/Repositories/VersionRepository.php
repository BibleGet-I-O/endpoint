<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

/**
 * Bible editions (versions_available, type BIBLE) and the flag that lets the
 * `reader` credential serve one whole, and the one that marks an edition as the
 * liturgical text of its language. `copyright` is the same column
 * QuoteContext reads into COPYRIGHT_VERSIONS.
 *
 * @phpstan-type VersionRow array{sigla: string, fullname: string, year: int, language: string, copyright: bool, reader_full_text: bool, liturgical: bool}
 */
class VersionRepository
{
    private const COLUMNS = 'sigla, fullname, year, language, copyright, reader_full_text, liturgical';

    public function __construct(private readonly \PDO $db)
    {
    }

    /**
     * @return list<VersionRow>
     */
    public function list(): array
    {
        $stmt = $this->db->query('SELECT ' . self::COLUMNS . " FROM versions_available WHERE type = 'BIBLE' ORDER BY sigla");
        $out  = [];
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                $r = Cast::row($row);
                if ($r !== null) {
                    $out[] = self::shape($r);
                }
            }
        }
        return $out;
    }

    /**
     * @return VersionRow|null
     */
    public function find(string $sigla): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . " FROM versions_available WHERE type = 'BIBLE' AND sigla = :s");
        $stmt->execute([':s' => $sigla]);
        $row = Cast::row($stmt->fetch());
        return $row === null ? null : self::shape($row);
    }

    /** False when there is no such edition. */
    public function setReaderFullText(string $sigla, bool $on): bool
    {
        $stmt = $this->db->prepare("UPDATE versions_available SET reader_full_text = :on WHERE type = 'BIBLE' AND sigla = :s");
        $stmt->bindValue(':on', $on, \PDO::PARAM_BOOL);
        $stmt->bindValue(':s', $sigla);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /** False when there is no such edition. */
    public function setLiturgical(string $sigla, bool $on): bool
    {
        $stmt = $this->db->prepare("UPDATE versions_available SET liturgical = :on WHERE type = 'BIBLE' AND sigla = :s");
        $stmt->bindValue(':on', $on, \PDO::PARAM_BOOL);
        $stmt->bindValue(':s', $sigla);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $r
     * @return VersionRow
     */
    private static function shape(array $r): array
    {
        return [
            'sigla'            => Cast::string($r['sigla'] ?? null),
            'fullname'         => Cast::string($r['fullname'] ?? null),
            'year'             => Cast::int($r['year'] ?? null),
            'language'         => Cast::string($r['language'] ?? null),
            'copyright'        => Cast::int($r['copyright'] ?? null) === 1,
            'reader_full_text' => Cast::bool($r['reader_full_text'] ?? null),
            'liturgical'       => Cast::bool($r['liturgical'] ?? null),
        ];
    }
}
