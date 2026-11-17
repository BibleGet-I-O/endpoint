<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

/**
 * Narrows mixed PDO row values to scalars.
 */
final class Cast
{
    public static function string(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    public static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function row(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /**
     * @param array<mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $r = self::row($row);
            if ($r !== null) {
                $out[] = $r;
            }
        }
        return $out;
    }
}
