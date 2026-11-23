<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers\Ops;

use BibleGet\Api\Handlers\Ops\MigrateHandler;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\ServerRequest;

/**
 * POST /_ops/migrate and GET /_ops/migrate/status against the PostgreSQL test database, with the real
 * configuration (doctrine-migrations.php) and its migrations, Version052NvbseCopyright first, then
 * Version053BtuReviewStore, Version054NvbseBracketedVerses and Version055NvbseOmittedVerses (the test database has
 * no NVBSE table, so 054 and 055 check nothing there).
 */
final class MigrateHandlerTest extends DatabaseTestCase
{
    private ?string $emptyDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnection()->exec('DROP TABLE IF EXISTS doctrine_migration_versions');
    }

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $pdo = $this->getConnection();
            $pdo->exec('DROP TABLE IF EXISTS doctrine_migration_versions');
            $pdo->exec('DROP TABLE IF EXISTS migration_probe');
            $pdo->exec("DELETE FROM versions_available WHERE sigla = 'NVBSE'");
        }
        if ($this->emptyDir !== null) {
            foreach (glob($this->emptyDir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->emptyDir);
        }
        parent::tearDown();
    }

    public function testPostAppliesThePendingMigrationsOnceAndRecordsThem(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec("INSERT INTO versions_available (sigla, fullname, year, language, type, copyright, copyright_holder)
                    VALUES ('NVBSE', 'Nova Vulgata', 1979, 'Latin', 'BIBLE', 0, '')");

        $first = ( new MigrateHandler(false) )->handle(new ServerRequest('POST', '/_ops/migrate'));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertStringContainsString('Version052NvbseCopyright', (string) $first->getBody());

        $row = $pdo->query("SELECT year, copyright, copyright_holder FROM versions_available WHERE sigla = 'NVBSE'");
        self::assertNotFalse($row);
        self::assertEquals(['year' => 1986, 'copyright' => 1, 'copyright_holder' => 'Libreria Editrice Vaticana'], $row->fetch());

        $versions = $pdo->query('SELECT version FROM doctrine_migration_versions ORDER BY version');
        self::assertNotFalse($versions);
        self::assertSame(
            [
                'BibleGet\\Api\\Migrations\\Version052NvbseCopyright',
                'BibleGet\\Api\\Migrations\\Version053BtuReviewStore',
                'BibleGet\\Api\\Migrations\\Version054NvbseBracketedVerses',
                'BibleGet\\Api\\Migrations\\Version055NvbseOmittedVerses',
                'BibleGet\\Api\\Migrations\\Version056LocalizedBookAbbreviations',
                'BibleGet\\Api\\Migrations\\Version057MoreLocalizedBookAbbreviations',
                'BibleGet\\Api\\Migrations\\Version058SearchVocabulary',
                'BibleGet\\Api\\Migrations\\Version059MediawikiPlugin',
                'BibleGet\\Api\\Migrations\\Version060ServiceNotices',
                'BibleGet\\Api\\Migrations\\Version061MoreBookNames',
                'BibleGet\\Api\\Migrations\\Version062EditionTitlesAndHolders',
                'BibleGet\\Api\\Migrations\\Version063ZechariahAbbreviation',
                'BibleGet\\Api\\Migrations\\Version064Notes',
            ],
            $versions->fetchAll(\PDO::FETCH_COLUMN)
        );
        $tables = $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_name IN ('btu_unit', 'btu_stretch', 'btu_link')");
        self::assertNotFalse($tables);
        self::assertSame(3, (int) $tables->fetchColumn());

        // Nothing left to apply: still a success, and nothing runs twice.
        $second = ( new MigrateHandler(false) )->handle(new ServerRequest('POST', '/_ops/migrate'));
        self::assertSame(200, $second->getStatusCode(), (string) $second->getBody());
        $count = $pdo->query('SELECT count(*) FROM doctrine_migration_versions');
        self::assertNotFalse($count);
        self::assertSame(13, (int) $count->fetchColumn());
    }

    public function testStatusListsTheMigrations(): void
    {
        $response = ( new MigrateHandler(true) )->handle(new ServerRequest('GET', '/_ops/migrate/status'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('BibleGet\\Api\\Migrations', (string) $response->getBody());
    }

    public function testEachActionAnswersOnlyItsMethod(): void
    {
        $post = ( new MigrateHandler(false) )->handle(new ServerRequest('GET', '/_ops/migrate'));
        self::assertSame(405, $post->getStatusCode());
        self::assertSame('POST', $post->getHeaderLine('Allow'));
        $status = ( new MigrateHandler(true) )->handle(new ServerRequest('POST', '/_ops/migrate/status'));
        self::assertSame(405, $status->getStatusCode());
    }

    public function testAMalformedTargetVersionIsRejectedBeforeAnythingRuns(): void
    {
        $response = ( new MigrateHandler(false) )->handle(
            ( new ServerRequest('POST', '/_ops/migrate') )->withQueryParams(['to' => "Version052'; DROP TABLE x;--"])
        );
        self::assertSame(400, $response->getStatusCode());
        $exists = $this->getConnection()->query("SELECT to_regclass('doctrine_migration_versions')");
        self::assertNotFalse($exists);
        self::assertNull($exists->fetchColumn());
    }

    public function testNoMigrationsAtAllIsASuccess(): void
    {
        $response = ( new MigrateHandler(false, null, $this->writeConfig([])) )->handle(new ServerRequest('POST', '/_ops/migrate'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('nothing to apply', (string) $response->getBody());
    }

    public function testAMigrationFileWithSeveralStatementsRunsWhole(): void
    {
        $config = $this->writeConfig(['BibleGet\\Tests\\Support\\Migrations' => dirname(__DIR__, 3) . '/Support/Migrations']);

        $response = ( new MigrateHandler(false, null, $config) )->handle(new ServerRequest('POST', '/_ops/migrate'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $sum = $this->getConnection()->query('SELECT sum(n) FROM migration_probe');
        self::assertNotFalse($sum);
        self::assertSame(3, (int) $sum->fetchColumn());
    }

    public function testASecondRunWhileOneHoldsTheLockAnswers409(): void
    {
        $holder = $this->getConnection();
        $holder->exec('SELECT pg_advisory_lock(' . MigrateHandler::LOCK_KEY . ')');
        try {
            $response = ( new MigrateHandler(false) )->handle(new ServerRequest('POST', '/_ops/migrate'));
        } finally {
            $holder->exec('SELECT pg_advisory_unlock(' . MigrateHandler::LOCK_KEY . ')');
        }
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $exists = $this->getConnection()->query("SELECT to_regclass('doctrine_migration_versions')");
        self::assertNotFalse($exists);
        self::assertNull($exists->fetchColumn());
    }

    /** @param array<string, string> $paths */
    private function writeConfig(array $paths): string
    {
        $dir = sys_get_temp_dir() . '/bibleget_migrate_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->emptyDir = $dir;
        $config         = $dir . '/config.php';
        file_put_contents($config, '<?php return ' . var_export([
            'migrations_paths' => $paths === [] ? ['BibleGet\\Tests\\Empty' => $dir] : $paths,
            'transactional'    => true,
            'all_or_nothing'   => true,
        ], true) . ';');
        return $config;
    }
}
