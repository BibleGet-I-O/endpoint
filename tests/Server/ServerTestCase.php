<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\TestCase;

/**
 * Base class for HTTP-level tests against the PHP built-in server.
 *
 * Starts the server once before all tests in the suite and stops it after.
 * Uses curl for requests to get full control over method, headers, and response parsing.
 */
abstract class ServerTestCase extends TestCase
{
    protected static string $host = '127.0.0.1';
    protected static int $port    = 8199; // non-standard port to avoid conflicts
    protected static string $baseUrl;

    /** @var int|null */
    private static ?int $serverPid = null;

    /** @var resource|null */
    private static $serverProcess = null;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('Server tests require POSIX extensions (not available on Windows).');
        }

        $envPort = getenv('TEST_SERVER_PORT');
        if ($envPort !== false && $envPort !== '') {
            $port = filter_var($envPort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) {
                self::fail('TEST_SERVER_PORT must be an integer between 1 and 65535, got: ' . $envPort);
            }
            self::$port = $port;
        }
        self::$baseUrl = 'http://' . self::$host . ':' . self::$port;

        // Don't start a second server if one is already running (e.g. shared across test classes)
        if (self::$serverPid !== null) {
            if (self::isProcessRunning(self::$serverPid)) {
                return;
            }
            // PID is stale — clean up the old proc handle before spawning a new one
            if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
                proc_close(self::$serverProcess);
                self::$serverProcess = null;
            }
            self::$serverPid = null;
        }

        $projectRoot = dirname(__DIR__, 2);
        $docRoot     = $projectRoot . '/public';
        $router      = $docRoot . '/router.php';

        $command = sprintf(
            'exec env PHP_CLI_SERVER_WORKERS=2 php -S %s:%d -t %s %s',
            self::$host,
            self::$port,
            escapeshellarg($docRoot),
            escapeshellarg($router)
        );

        // Start server in background, redirect output to /dev/null
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            self::fail('Failed to start PHP built-in server');
        }

        $status              = proc_get_status($process);
        self::$serverPid     = $status['pid'];
        self::$serverProcess = $process;

        // Wait for server to be ready (up to 3 seconds), verifying the process is still alive
        $ready = false;
        for ($i = 0; $i < 30; $i++) {
            if (!self::isProcessRunning(self::$serverPid)) {
                break;
            }
            $sock = @fsockopen(self::$host, self::$port, $errno, $errstr, 0.1);
            if ($sock) {
                fclose($sock);
                $ready = true;
                break;
            }
            usleep(100_000); // 100ms
        }

        if (!$ready) {
            self::stopServer();
            self::fail('PHP built-in server failed to start within 3 seconds');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
    }

    private static function stopServer(): void
    {
        if (self::$serverPid !== null) {
            // Kill the process group (server + workers)
            @posix_kill(-self::$serverPid, SIGTERM);
            // Also kill the main PID directly
            @posix_kill(self::$serverPid, SIGTERM);
            usleep(200_000);
            if (self::isProcessRunning(self::$serverPid)) {
                @posix_kill(-self::$serverPid, SIGKILL);
                @posix_kill(self::$serverPid, SIGKILL);
            }
            self::$serverPid = null;
        }
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
    }

    private static function isProcessRunning(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    // ── HTTP helpers ──────────────────────────────────────────

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected static function httpGet(string $path, array $headers = []): array
    {
        return self::httpRequest('GET', $path, null, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected static function httpPost(string $path, ?string $body = null, array $headers = []): array
    {
        return self::httpRequest('POST', $path, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected static function httpOptions(string $path, array $headers = []): array
    {
        return self::httpRequest('OPTIONS', $path, null, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected static function httpDelete(string $path, array $headers = []): array
    {
        return self::httpRequest('DELETE', $path, null, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function httpRequest(string $method, string $path, ?string $body, array $headers): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        if ($ch === false) {
            self::fail('curl_init failed');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }
        if (!empty($curlHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            self::fail('curl request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders   = substr((string) $raw, 0, $headerSize);
        $responseBody = substr((string) $raw, $headerSize);

        $parsedHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $key            = strtolower(trim($name));
                // Append multiple values for the same header (e.g. Vary)
                if (isset($parsedHeaders[$key])) {
                    $parsedHeaders[$key] .= ', ' . trim($value);
                } else {
                    $parsedHeaders[$key] = trim($value);
                }
            }
        }

        return [
            'status'  => $statusCode,
            'headers' => $parsedHeaders,
            'body'    => $responseBody,
        ];
    }

    /**
     * Decode a JSON response body.
     *
     * @return array<string, mixed>
     */
    protected static function jsonBody(string $body): array
    {
        $data = json_decode($body, true);
        self::assertIsArray($data, 'Response body is not valid JSON: ' . substr($body, 0, 200));
        return $data;
    }
}
