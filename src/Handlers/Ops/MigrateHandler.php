<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Ops;

use BibleGet\Api\Database\Connection as Pdo;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\Migrations\Tools\Console\Command\StatusCommand;
use Doctrine\Migrations\Tools\Console\Command\SyncMetadataCommand;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Runs Doctrine Migrations in-process, for the deploy workflow (the deploy user cannot run PHP against the
 * database over SSH; the PHP serving the API already has its credentials). Ported from the Liturgical
 * Calendar API.
 *
 *   POST /_ops/migrate          apply the pending migrations
 *   POST /_ops/migrate?to=<v>   migrate to a given version (a class name, e.g. Version052NvbseCopyright)
 *   GET  /_ops/migrate/status   applied and pending versions
 *
 * DeployTokenMiddleware, piped before it by the Router, authenticates the request. The answer is Doctrine's
 * own output as text/plain: 200 when the command succeeded, 500 otherwise (with the reason in the body).
 */
final class MigrateHandler implements RequestHandlerInterface
{
    /**
     * The PostgreSQL advisory lock a run holds, so that a second POST (a retried or a parallel deploy) answers
     * 409 instead of running the same migrations at the same time.
     */
    public const int LOCK_KEY = 4_220_052;

    public function __construct(
        private readonly bool $status,
        private ?Connection $connection = null,
        private readonly ?string $configFile = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        if ($method !== ( $this->status ? 'GET' : 'POST' )) {
            return new Response(405, ['Allow' => $this->status ? 'GET' : 'POST', 'Content-Type' => 'text/plain'], "Method Not Allowed\n");
        }

        $to = null;
        if (!$this->status) {
            $param = $request->getQueryParams()['to'] ?? null;
            if (is_string($param) && $param !== '') {
                if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $param) !== 1) {
                    return new Response(400, ['Content-Type' => 'text/plain'], "Invalid 'to' parameter\n");
                }
                $to = $param;
            }
        }

        // A migration must not be cut off half-way by the time limit or by the client going away.
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        ignore_user_abort(true);

        $configFile = $this->configFile ?? dirname(__DIR__, 3) . '/doctrine-migrations.php';
        $count      = self::countMigrations($configFile);
        $factory    = DependencyFactory::fromConnection(
            new PhpFile($configFile),
            new ExistingConnection($this->connection ??= self::connectionFromEnv())
        );
        $app = new Application('BibleGet Migrations');
        $app->setAutoExit(false);
        $app->setCatchExceptions(true);
        $app->addCommands([new SyncMetadataCommand($factory), new MigrateCommand($factory), new StatusCommand($factory)]);

        $connection = $this->connection;
        if (!$this->status && $connection->fetchOne('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')') !== true) {
            return new Response(409, ['Content-Type' => 'text/plain'], "Another migration run is in progress; check GET /_ops/migrate/status.\n");
        }
        try {
            return $this->run($app, $count, $to);
        } finally {
            if (!$this->status) {
                $connection->executeStatement('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
            }
        }
    }

    private function run(Application $app, int $count, ?string $to): ResponseInterface
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return new Response(500, ['Content-Type' => 'text/plain'], "Failed to open output stream\n");
        }
        $output = new StreamOutput($stream);

        if ($this->status) {
            $exit = $app->run(new ArrayInput(['command' => 'migrations:status', '--no-interaction' => true]), $output);
        } else {
            // Creates the tracking table when missing (idempotent), then migrates.
            $exit = $app->run(new ArrayInput(['command' => 'migrations:sync-metadata-storage', '--no-interaction' => true]), $output);
            if ($exit === 0 && $count === 0) {
                // migrate fails on "latest" with no migrations at all: nothing to apply is a success.
                fwrite($stream, "  [INFO] No migration files registered; nothing to apply.\n");
            } elseif ($exit === 0) {
                $input = ['command' => 'migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true];
                if ($to !== null) {
                    $input['version'] = $to;
                }
                $exit = $app->run(new ArrayInput($input), $output);
            }
        }

        rewind($stream);
        $body = stream_get_contents($stream);
        fclose($stream);

        return new Response($exit === 0 ? 200 : 500, ['Content-Type' => 'text/plain; charset=utf-8'], $body === false ? '' : $body);
    }

    /**
     * The Version*.php files in the configured migrations_paths. A misconfigured path throws rather than
     * counting as "no migrations", so a broken deploy fails loudly.
     */
    private static function countMigrations(string $configFile): int
    {
        /** @var mixed $config */
        $config = is_file($configFile) ? include $configFile : null;
        $paths  = is_array($config) ? ( $config['migrations_paths'] ?? null ) : null;
        if (!is_array($paths)) {
            throw new \RuntimeException(sprintf('Migrations config %s is missing or has no migrations_paths array.', $configFile));
        }
        $count = 0;
        foreach ($paths as $dir) {
            if (!is_string($dir) || !is_dir($dir)) {
                throw new \RuntimeException(sprintf('migrations_paths entry %s is not a directory.', is_string($dir) ? $dir : '(non-string)'));
            }
            $count += count(glob($dir . '/Version*.php') ?: []);
        }
        return $count;
    }

    private static function connectionFromEnv(): Connection
    {
        $p = Pdo::params();
        return DriverManager::getConnection([
            'driver'   => 'pdo_pgsql',
            'host'     => $p['host'],
            'port'     => (int) $p['port'],
            'dbname'   => $p['dbname'],
            'user'     => $p['user'],
            'password' => $p['password'],
            'charset'  => 'UTF8',
        ]);
    }
}
