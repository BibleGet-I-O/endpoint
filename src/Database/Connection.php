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
     */
    public static function getConnection(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::loadCredentials();

        if (!defined('SERVER') || !defined('DBUSER') || !defined('DBPASS') || !defined('DATABASE')) {
            throw new InternalServerErrorException('Database credentials not found.');
        }

        /** @var string $server */
        $server = SERVER;
        /** @var string $dbuser */
        $dbuser = DBUSER;
        /** @var string $dbpass */
        $dbpass = DBPASS;
        /** @var string $database */
        $database = DATABASE;
        $portRaw  = defined('DBPORT') ? DBPORT : 5432;
        $port     = is_int($portRaw) ? $portRaw : ( is_numeric($portRaw) ? (int) $portRaw : 5432 );

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

        if (defined('WHITELISTED_DOMAINS_IPS') && is_array(WHITELISTED_DOMAINS_IPS)) {
            /** @var array<string> $wl */
            $wl                          = WHITELISTED_DOMAINS_IPS;
            self::$whitelistedDomainsIPs = $wl;
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
     * Search for dbcredentials.php up to three directory levels from the public/ entry point.
     */
    private static function loadCredentials(): void
    {
        // If credentials are already defined (e.g. by test fixtures), skip file search
        if (defined('SERVER') && defined('DBUSER') && defined('DBPASS') && defined('DATABASE')) {
            return;
        }

        $dbCredentials = 'dbcredentials.php';

        // Search from the project root (one level up from public/)
        $baseDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        $searchPaths = [
            $baseDir . $dbCredentials,
            dirname($baseDir) . DIRECTORY_SEPARATOR . $dbCredentials,
            dirname($baseDir, 2) . DIRECTORY_SEPARATOR . $dbCredentials,
        ];

        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                include_once $path;
                return;
            }
        }

        throw new InternalServerErrorException('Database credentials file not found.');
    }
}
