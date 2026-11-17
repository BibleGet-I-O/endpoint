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

    /**
     * Running servers keyed by port, so a subclass that needs a differently
     * configured server (see serverEnv()) gets its own process.
     *
     * @var array<int, array{pid: int, process: resource}>
     */
    private static array $servers = [];

    /**
     * Extra environment for the spawned server. Dotenv::createImmutable in the
     * front controller does not override variables already in the process
     * environment, so these win over .env.test.
     *
     * @return array<string, string>
     */
    protected static function serverEnv(): array
    {
        return [];
    }

    /**
     * Port for this class's server; null uses TEST_SERVER_PORT or the default.
     * A subclass with a non-empty serverEnv() must return a distinct port.
     */
    protected static function serverPort(): ?int
    {
        return null;
    }

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('Server tests require POSIX extensions (not available on Windows).');
        }

        self::$port    = static::serverPort() ?? self::defaultPort();
        self::$baseUrl = 'http://' . self::$host . ':' . self::$port;
        $port          = self::$port;

        // Don't start a second server if one is already running on this port
        if (isset(self::$servers[$port])) {
            if (self::isProcessRunning(self::$servers[$port]['pid'])) {
                return;
            }
            // PID is stale — clean up the old proc handle before spawning a new one
            self::stopServer($port);
        }

        $projectRoot = dirname(__DIR__, 2);
        $docRoot     = $projectRoot . '/public';
        $router      = $docRoot . '/router.php';

        $envArgs = '';
        foreach (static::serverEnv() as $name => $value) {
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
                self::fail('Invalid server environment variable name: ' . $name);
            }
            $envArgs .= ' ' . escapeshellarg($name . '=' . $value);
        }

        $command = sprintf(
            'exec env PHP_CLI_SERVER_WORKERS=2%s php -S %s:%d -t %s %s',
            $envArgs,
            self::$host,
            $port,
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

        $status               = proc_get_status($process);
        $pid                  = $status['pid'];
        self::$servers[$port] = ['pid' => $pid, 'process' => $process];

        // Wait for server to be ready (up to 3 seconds), verifying the process is still alive
        $ready = false;
        for ($i = 0; $i < 30; $i++) {
            if (!self::isProcessRunning($pid)) {
                break;
            }
            $sock = @fsockopen(self::$host, $port, $errno, $errstr, 0.1);
            if ($sock) {
                fclose($sock);
                $ready = true;
                break;
            }
            usleep(100_000); // 100ms
        }

        if (!$ready) {
            self::stopServer($port);
            self::fail('PHP built-in server failed to start within 3 seconds');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer(self::$port);
    }

    private static function defaultPort(): int
    {
        $envPort = getenv('TEST_SERVER_PORT');
        if ($envPort === false || $envPort === '') {
            return 8199;
        }
        $port = filter_var($envPort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            self::fail('TEST_SERVER_PORT must be an integer between 1 and 65535, got: ' . $envPort);
        }
        return $port;
    }

    private static function stopServer(int $port): void
    {
        if (!isset(self::$servers[$port])) {
            return;
        }
        ['pid' => $pid, 'process' => $process] = self::$servers[$port];
        unset(self::$servers[$port]);

        // Kill the process group (server + workers)
        @posix_kill(-$pid, SIGTERM);
        // Also kill the main PID directly
        @posix_kill($pid, SIGTERM);
        usleep(200_000);
        if (self::isProcessRunning($pid)) {
            @posix_kill(-$pid, SIGKILL);
            @posix_kill($pid, SIGKILL);
        }
        if (is_resource($process)) {
            proc_close($process);
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
    protected static function httpPatch(string $path, ?string $body = null, array $headers = []): array
    {
        return self::httpRequest('PATCH', $path, $body, $headers);
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
        $url = rtrim(self::$baseUrl, '/') . '/' . ltrim($path, '/');
        $ch  = curl_init($url);
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
                // Use newline for set-cookie since commas are ambiguous in cookie values
                if (isset($parsedHeaders[$key])) {
                    $separator            = $key === 'set-cookie' ? "\n" : ', ';
                    $parsedHeaders[$key] .= $separator . trim($value);
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
     * @return array<array-key, mixed>
     */
    protected static function jsonBody(string $body): array
    {
        $data = json_decode($body, true);
        self::assertIsArray($data, 'Response body is not valid JSON: ' . substr($body, 0, 200));
        return $data;
    }
}
