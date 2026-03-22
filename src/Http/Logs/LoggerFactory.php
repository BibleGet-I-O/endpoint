<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Logs;

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;

class LoggerFactory
{
    /** @var array<string, Logger> */
    private static array $loggers             = [];
    private static ?string $defaultLogsFolder = null;

    private static function resolveLogsFolder(?string $logsFolder): string
    {
        $logsFolder              ??= self::$defaultLogsFolder
            ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'logs';
        self::$defaultLogsFolder ??= $logsFolder;

        if (!is_dir($logsFolder)) {
            if (!@mkdir($logsFolder, 0755, true) && !is_dir($logsFolder)) {
                throw new \RuntimeException('Failed to create logs directory: ' . $logsFolder);
            }
        }

        return $logsFolder;
    }

    public static function create(
        string $logName = 'api',
        ?string $logsFolder = null,
        int $maxFiles = 30,
        bool $debug = false,
        bool $includeJsonHandler = true
    ): Logger {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $logName)) {
            throw new \InvalidArgumentException('Invalid log name: must contain only alphanumeric characters, underscores, dots, and hyphens.');
        }

        $logsFolder = self::resolveLogsFolder($logsFolder);
        $cacheKey   = implode('|', [
            $logName,
            $logsFolder,
            (string) $maxFiles,
            $debug ? '1' : '0',
            $includeJsonHandler ? '1' : '0',
        ]);
        if (isset(self::$loggers[$cacheKey])) {
            return self::$loggers[$cacheKey];
        }

        $logger = new Logger('bibleget-api');

        // Plain text rotating file handler
        $plainHandler   = new RotatingFileHandler("{$logsFolder}/{$logName}.log", $maxFiles, $debug ? Level::Debug : Level::Info);
        $plainFormatter = new LineFormatter(
            "[%datetime%] %level_name%: %message%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $plainHandler->setFormatter($plainFormatter);
        $logger->pushHandler($plainHandler);

        if ($includeJsonHandler) {
            $jsonHandler   = new RotatingFileHandler("{$logsFolder}/{$logName}.json.log", $maxFiles, $debug ? Level::Debug : Level::Info);
            $jsonFormatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true);
            $jsonHandler->setFormatter($jsonFormatter);
            $logger->pushHandler($jsonHandler);
        }

        self::$loggers[$cacheKey] = $logger;
        return $logger;
    }

    /**
     * Reset the logger cache (for testing only).
     */
    public static function reset(): void
    {
        self::$loggers           = [];
        self::$defaultLogsFolder = null;
    }
}
