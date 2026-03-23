<?php

declare(strict_types=1);

namespace BibleGet\Api\Database;

use BibleGet\Api\Http\Exception\InternalServerErrorException;

class Connection
{
    private static ?\PDO $instance = null;

    /** @var string[] */
    private static array $whitelistedDomainsIPs = [];

    /**
     * Get (or create) the shared PDO connection.
     *
     * Reads credentials from environment variables (loaded by phpdotenv in the front controller
     * or set directly via Docker/CI environment). Required variables: DB_HOST, DB_USER, DB_PASS, DB_NAME.
     */
    public static function getConnection(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $server   = self::env('DB_HOST');
        $dbuser   = self::env('DB_USER');
        $dbpass   = self::env('DB_PASS');
        $database = self::env('DB_NAME');
        $port     = self::env('DB_PORT', '5432');

        if ($server === '' || $dbuser === '' || $database === '') {
            throw new InternalServerErrorException(
                'Database credentials not found. Set DB_HOST, DB_USER, DB_PASS, DB_NAME environment variables.'
            );
        }

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
            throw new InternalServerErrorException(
                'Failed to connect to database: ' . $e->getMessage()
            );
        }

        $pdo->exec("SET client_encoding = 'UTF8'");
        self::$instance = $pdo;

        $whitelistRaw = self::env('WHITELISTED_DOMAINS_IPS');
        if ($whitelistRaw !== '') {
            self::$whitelistedDomainsIPs = array_filter(
                array_map('trim', explode(',', $whitelistRaw))
            );
        }

        return self::$instance;
    }

    /**
     * @return string[]
     */
    public static function getWhitelistedDomainsIPs(): array
    {
        return self::$whitelistedDomainsIPs;
    }

    /**
     * Reset the singleton (for testing only).
     */
    public static function reset(): void
    {
        self::$instance              = null;
        self::$whitelistedDomainsIPs = [];
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
