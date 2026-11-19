<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Database;

use BibleGet\Api\Database\SqlFileMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlFileMigrationTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function sql(): iterable
    {
        yield 'a plain update'              => ["UPDATE t SET a = 1 WHERE b = 2;\n", false];
        yield 'BEGIN and COMMIT'            => ["BEGIN;\nUPDATE t SET a = 1;\nCOMMIT;\n", true];
        yield 'lowercase begin transaction' => ["begin transaction;\nupdate t set a = 1;\n", true];
        yield 'START TRANSACTION'           => ["START TRANSACTION;\nUPDATE t SET a = 1;\n", true];
        yield 'a trailing COMMIT only'      => ["UPDATE t SET a = 1;\nCOMMIT\n", true];
        yield 'BEGIN inside a DO block'     => ["DO \$\$\nBEGIN\n  UPDATE t SET a = 1;\nEND\n\$\$;\n", false];
        yield 'a tagged DO body'            => ["DO \$body\$\nBEGIN\n  PERFORM 1;\nEND;\n\$body\$;\n", false];
        yield 'BEGIN in a comment'          => ["-- BEGIN;\n/* COMMIT; */\nUPDATE t SET a = 1;\n", false];
        yield 'a column named begin_date'   => ["UPDATE t SET begin_date = now();\n", false];
        yield 'COMMIT WORK'                 => ["UPDATE t SET a = 1;\nCOMMIT WORK;\n", true];
        yield 'COMMIT AND CHAIN'            => ["UPDATE t SET a = 1;\nCOMMIT AND CHAIN;\n", true];
        yield 'END TRANSACTION'             => ["UPDATE t SET a = 1;\nEND TRANSACTION;\n", true];
        yield 'ROLLBACK WORK'               => ["UPDATE t SET a = 1;\nROLLBACK WORK;\n", true];
        yield 'BEGIN ISOLATION LEVEL'       => ["BEGIN ISOLATION LEVEL SERIALIZABLE;\nUPDATE t SET a = 1;\n", true];
        yield 'START TRANSACTION READ ONLY' => ["START TRANSACTION READ ONLY;\n", true];
        yield 'SAVEPOINT'                   => ["SAVEPOINT s1;\nUPDATE t SET a = 1;\nRELEASE s1;\n", true];
        yield 'PREPARE TRANSACTION'         => ["UPDATE t SET a = 1;\nPREPARE TRANSACTION 'x';\n", true];
        yield 'COMMIT after a -- string'    => ["UPDATE t SET a = '--';\nCOMMIT;\n", true];
        yield 'COMMIT inside a string'      => ["UPDATE t SET note = 'x; COMMIT; y';\n", false];
        yield 'a dollar tag with digits'    => ["DO \$f1\$\nBEGIN\n  PERFORM 1;\nEND;\n\$f1\$;\n", false];
    }

    #[DataProvider('sql')]
    public function testControlsTransactionOnlyForStatementsOfItsOwn(string $sql, bool $expected): void
    {
        self::assertSame($expected, SqlFileMigration::controlsTransaction($sql));
    }

    public function testMigration051WouldBeRefusedForItsOwnBeginCommit(): void
    {
        $sql = (string) file_get_contents(SqlFileMigration::directory() . '/051-liturgical-editions.sql');
        self::assertTrue(SqlFileMigration::controlsTransaction($sql));
    }

    public function testEveryDoctrineMigrationFileIsUsable(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Migrations/Version*.php') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $class = 'BibleGet\\Api\\Migrations\\' . basename($file, '.php');
            self::assertTrue(is_subclass_of($class, SqlFileMigration::class), "$class is a SqlFileMigration");
            $migration = ( new \ReflectionClass($class) )->newInstanceWithoutConstructor();
            // Throws when the file is missing, empty or opens a transaction of its own.
            self::assertNotSame('', $migration->sql());
        }
    }
}
