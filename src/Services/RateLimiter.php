<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Http\Logs\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sliding-window request limiter, one JSON file per identifier, adapted from
 * the Liturgical Calendar API's RateLimiter.
 *
 * Each file holds the timestamps of the requests allowed within the window;
 * it is read, pruned and rewritten under an exclusive flock, so concurrent
 * PHP-FPM workers count every request once. Denied requests are not
 * recorded, so a file never holds more than `$limit` timestamps.
 *
 * Fails open: when the storage cannot be created, locked, read or written the
 * request is allowed and an error is logged — a broken limiter must never
 * take the API down.
 */
class RateLimiter
{
    public const DEFAULT_STORAGE = 'logs/rate_limits';

    private readonly string $storagePath;
    private readonly LoggerInterface $logger;
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param string|null           $storagePath directory for the files (default RATE_LIMIT_STORAGE_PATH, else logs/rate_limits)
     * @param int                   $windowSeconds length of the sliding window
     * @param LoggerInterface|null  $logger receives storage errors (default: the `rate-limiter` log)
     * @param (\Closure(): int)|null $clock current UNIX time, injectable for tests
     */
    public function __construct(
        ?string $storagePath = null,
        private readonly int $windowSeconds = 3600,
        ?LoggerInterface $logger = null,
        ?\Closure $clock = null
    ) {
        $this->storagePath = rtrim($storagePath ?? self::configuredStoragePath(), DIRECTORY_SEPARATOR);
        $this->logger      = $logger ?? self::defaultLogger();
        $this->clock       = $clock ?? static fn (): int => time();
    }

    public static function configuredStoragePath(): string
    {
        $path = CredentialFactory::env('RATE_LIMIT_STORAGE_PATH');
        if ($path === null || trim($path) === '') {
            return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::DEFAULT_STORAGE;
        }
        return trim($path);
    }

    /**
     * Records one request for `$identifier` if it is within `$limit` per window.
     *
     * @return array{allowed: bool, remaining: int, reset: int} `reset` is the UNIX time the oldest
     *                                                           counted request leaves the window
     */
    public function hit(string $identifier, int $limit): array
    {
        $now  = ( $this->clock )();
        $open = ['allowed' => true, 'remaining' => $limit, 'reset' => $now + $this->windowSeconds];
        try {
            $handle = $this->open($identifier);
        } catch (\Throwable $e) {
            $this->logger->error('Rate limiter storage unavailable, request allowed: ' . $e->getMessage(), ['identifier' => $identifier]);
            return $open;
        }

        try {
            // Read the clock again: waiting for the lock may have taken a while.
            return $this->record($handle, ( $this->clock )(), $limit, $identifier);
        } catch (\Throwable $e) {
            $this->logger->error('Rate limiter failed, request allowed: ' . $e->getMessage(), ['identifier' => $identifier]);
            return $open;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource an open, exclusively locked handle on the identifier's file
     */
    private function open(string $identifier)
    {
        if (!is_dir($this->storagePath) && !@mkdir($this->storagePath, 0750, true) && !is_dir($this->storagePath)) {
            throw new \RuntimeException('cannot create ' . $this->storagePath);
        }
        $file   = $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', $identifier) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('cannot open ' . $file);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('cannot lock ' . $file);
        }
        return $handle;
    }

    /**
     * @param resource $handle
     * @return array{allowed: bool, remaining: int, reset: int}
     */
    private function record($handle, int $now, int $limit, string $identifier): array
    {
        $cutoff   = $now - $this->windowSeconds;
        $attempts = array_values(array_filter(self::read($handle), static fn (int $t): bool => $t > $cutoff));
        $allowed  = count($attempts) < $limit;
        if ($allowed) {
            $attempts[] = $now;
            if (!self::write($handle, $attempts)) {
                $this->logger->error('Rate limiter could not write its window file', ['identifier' => $identifier]);
            }
        }
        $reset = ( $attempts === [] ? $now : min($attempts) ) + $this->windowSeconds;
        return ['allowed' => $allowed, 'remaining' => max(0, $limit - count($attempts)), 'reset' => $reset];
    }

    /**
     * @param resource $handle
     * @return list<int>
     */
    private static function read($handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        $data     = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $attempts = [];
        foreach ($data as $t) {
            if (is_int($t)) {
                $attempts[] = $t;
            }
        }
        return $attempts;
    }

    /**
     * @param resource  $handle
     * @param list<int> $attempts
     */
    private static function write($handle, array $attempts): bool
    {
        return rewind($handle)
            && ftruncate($handle, 0)
            && fwrite($handle, (string) json_encode($attempts)) !== false
            && fflush($handle);
    }

    private static function defaultLogger(): LoggerInterface
    {
        try {
            return LoggerFactory::create('rate-limiter');
        } catch (\Throwable) {
            return new NullLogger();
        }
    }
}
