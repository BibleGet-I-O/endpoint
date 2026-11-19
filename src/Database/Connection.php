<?php

declare(strict_types=1);

namespace BibleGet\Api\Database;

use BibleGet\Api\Http\Exception\InternalServerErrorException;

class Connection
{
    private static ?\PDO $instance = null;

    /**
     * Get (or create) the shared PDO connection.
     *
     * Reads credentials from environment variables (loaded by phpdotenv in the front controller
     * or set directly via Docker/CI environment). Required variables: DB_HOST, DB_USER, DB_NAME. DB_PASS may be empty for local development.
     */
    public static function getConnection(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        ['host' => $server, 'port' => $port, 'dbname' => $database, 'user' => $dbuser, 'password' => $dbpass] = self::params();

        try {
            $pdo = new \PDO(
                "pgsql:host={$server};port={$port};dbname={$database}",
                $dbuser,
                $dbpass,
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (\PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new InternalServerErrorException(
                'Failed to connect to database. Please check server configuration.'
            );
        }

        $pdo->exec("SET client_encoding = 'UTF8'");
        self::$instance = $pdo;

        return self::$instance;
    }

    /**
     * The connection settings from the environment (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS), shared by the
     * PDO connection above and the Doctrine connection of the migrations runner.
     *
     * @return array{host: string, port: string, dbname: string, user: string, password: string}
     * @throws InternalServerErrorException when DB_HOST, DB_USER or DB_NAME is missing
     */
    public static function params(): array
    {
        $params = [
            'host'     => self::env('DB_HOST'),
            'port'     => self::env('DB_PORT', '5432'),
            'dbname'   => self::env('DB_NAME'),
            'user'     => self::env('DB_USER'),
            'password' => self::env('DB_PASS'),
        ];
        if ($params['host'] === '' || $params['user'] === '' || $params['dbname'] === '') {
            throw new InternalServerErrorException(
                'Database credentials not found. Set DB_HOST, DB_USER, DB_PASS, DB_NAME environment variables.'
            );
        }
        return $params;
    }

    /**
     * Reset the singleton (for testing only).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Read an environment variable from $_ENV, $_SERVER, or getenv().
     */
    private static function env(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        $val = getenv($key);
        return $val !== false ? $val : $default;
    }
}
