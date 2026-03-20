<?php

declare(strict_types=1);

namespace BibleGet\Api\Database;

use BibleGet\Api\Http\Exception\InternalServerErrorException;

class Connection
{
    private static ?\mysqli $instance = null;

    /** @var string[] */
    private static array $whitelistedDomainsIPs = [];

    /**
     * Get (or create) the shared mysqli connection.
     */
    public static function getConnection(): \mysqli
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
        $mysqli = new \mysqli($server, $dbuser, $dbpass, $database);

        if ($mysqli->connect_errno) {
            throw new InternalServerErrorException(
                'Failed to connect to MySQL: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error
            );
        }

        $mysqli->set_charset('utf8');
        self::$instance = $mysqli;

        if (defined('WHITELISTED_DOMAINS_IPS') && is_array(WHITELISTED_DOMAINS_IPS)) {
            /** @var array<string> $wl */
            $wl = WHITELISTED_DOMAINS_IPS;
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
        if (self::$instance !== null && self::$instance->thread_id) {
            self::$instance->close();
        }
        self::$instance = null;
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
