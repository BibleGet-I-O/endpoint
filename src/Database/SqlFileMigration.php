<?php

declare(strict_types=1);

namespace BibleGet\Api\Database;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A Doctrine migration whose SQL lives in a file of migrations/, so that each change stays a plain, reviewable
 * SQL file as before Doctrine (and integration tests can run the file directly).
 *
 * Doctrine runs the pending migrations in one transaction (doctrine-migrations.php: transactional, all_or_nothing), so the
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

    /** Where the file is: migrations/ (tests point elsewhere). */
    protected function path(): string
    {
        return self::directory() . '/' . $this->file();
    }

    /** The file's SQL, checked: readable, not empty, no transaction control of its own. */
    public function sql(): string
    {
        $path = $this->path();
        $sql  = is_readable($path) ? file_get_contents($path) : false;
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException(sprintf('Migration file %s is missing or empty.', $path));
        }
        if (self::controlsTransaction($sql)) {
            throw new \RuntimeException(sprintf(
                'Migration file %s controls its own transaction; Doctrine runs the migrations in a transaction of its own, so remove its BEGIN/COMMIT.',
                $path
            ));
        }
        return $sql;
    }

    /**
     * Whether the SQL controls a transaction with a statement of its own (BEGIN, START TRANSACTION, COMMIT,
     * END, ROLLBACK, ABORT, SAVEPOINT, RELEASE, PREPARE TRANSACTION, in any of their forms). Such a statement
     * would end Doctrine's transaction half-way through the batch. String literals, dollar-quoted bodies and
     * comments are blanked first, in one left-to-right pass, so the BEGIN and END of a DO $$ ... $$ block, or a
     * "COMMIT" inside a string, are not taken for one. Errs on the side of refusing (a BEGIN ATOMIC function
     * body is refused too).
     */
    public static function controlsTransaction(string $sql): bool
    {
        $code = preg_replace(
            '/(\$(?:[A-Za-z_][A-Za-z_0-9]*)?\$).*?\1|\'(?:[^\']|\'\')*\'|--[^\n]*|\/\*.*?\*\//s',
            ' ',
            $sql
        ) ?? $sql;
        return preg_match(
            '/(?:^|;)\s*(?:BEGIN|START\s+TRANSACTION|COMMIT|END|ROLLBACK|ABORT|SAVEPOINT|RELEASE|PREPARE\s+TRANSACTION)\b/i',
            $code
        ) === 1;
    }

    /**
     * Runs the whole file at once, inside the transaction Doctrine has opened. Not addSql(): Doctrine runs each
     * addSql() as a prepared statement, and PostgreSQL refuses a prepared statement with several commands
     * ("cannot insert multiple commands into a prepared statement"). executeStatement() without parameters
     * goes through PDO::exec, which accepts them. The cost: --dry-run and --write-sql do not show the SQL;
     * the file itself is the record.
     */
    public function up(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql());
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
