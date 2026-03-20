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
    /** @var Logger[] */
    private static array $loggers = [];
    private static string $logsFolder;

    private static function resolveLogsFolder(?string $logsFolder): string
    {
        if ($logsFolder !== null) {
            self::$logsFolder = $logsFolder;
        } elseif (isset(self::$logsFolder)) {
            $logsFolder = self::$logsFolder;
        } else {
            // Default to logs/ in the project root (three levels up from this file: src/Http/Logs/)
            self::$logsFolder = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'logs';
            $logsFolder       = self::$logsFolder;
        }

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
        if (isset(self::$loggers[$logName])) {
            return self::$loggers[$logName];
        }

        $logsFolder = self::resolveLogsFolder($logsFolder);
        $logger     = new Logger('bibleget-api');

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

        self::$loggers[$logName] = $logger;
        return $logger;
    }
}
