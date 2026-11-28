<?php

declare(strict_types=1);

namespace BibleGet\Tests\Support;

/**
 * A PDOStatement that counts its executions by SQL (PDO::ATTR_STATEMENT_CLASS), so a test can tell how many round
 * trips a piece of code makes.
 */
class CountingStatement extends \PDOStatement
{
    /** @var array<string, int> executions by query string */
    public static array $executed = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$executed[$this->queryString] = ( self::$executed[$this->queryString] ?? 0 ) + 1;
        return parent::execute($params);
    }

    /** The executions of the statements whose SQL contains $needle. */
    public static function count(string $needle): int
    {
        $n = 0;
        foreach (self::$executed as $sql => $times) {
            if (str_contains($sql, $needle)) {
                $n += $times;
            }
        }
        return $n;
    }
}
