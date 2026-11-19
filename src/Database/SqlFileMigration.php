<?php

declare(strict_types=1);

namespace BibleGet\Api\Database;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A Doctrine migration whose SQL lives in a file of migrations/, so that each change stays a plain, reviewable
 * SQL file as before Doctrine (and integration tests can run the file directly).
 *
 * Doctrine runs each migration in a transaction of its own (doctrine-migrations.php: transactional), so the
 * file must not open or close one: a BEGIN or COMMIT in it is refused before anything runs. Not reversible.
 */
abstract class SqlFileMigration extends AbstractMigration
{
    /** The file's name in migrations/, e.g. "052-nvbse-copyright.sql". */
    abstract protected function file(): string;

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    /** The file's SQL, checked: readable, not empty, no transaction control of its own. */
    public function sql(): string
    {
        $path = self::directory() . '/' . $this->file();
        $sql  = is_readable($path) ? file_get_contents($path) : false;
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException(sprintf('Migration file %s is missing or empty.', $path));
        }
        if (self::controlsTransaction($sql)) {
            throw new \RuntimeException(sprintf(
                'Migration file %s controls its own transaction; Doctrine opens one for each migration, so remove its BEGIN/COMMIT.',
                $path
            ));
        }
        return $sql;
    }

    /**
     * Whether the SQL opens or closes a transaction as a statement of its own. Comments and dollar-quoted
     * bodies are left out first: the BEGIN and END of a DO $$ ... $$ block are not transaction control.
     */
    public static function controlsTransaction(string $sql): bool
    {
        $code = preg_replace(
            ['/--[^\n]*/', '#/\*.*?\*/#s', '/(\$[A-Za-z_]*\$).*?\1/s'],
            ['', '', "''"],
            $sql
        ) ?? $sql;
        return preg_match(
            '/(?:^|;)\s*(?:BEGIN(?:\s+(?:WORK|TRANSACTION))?|START\s+TRANSACTION|COMMIT|END|ROLLBACK|ABORT)\s*(?:;|$)/i',
            $code
        ) === 1;
    }

    public function up(Schema $schema): void
    {
        $this->addSql($this->sql());
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
