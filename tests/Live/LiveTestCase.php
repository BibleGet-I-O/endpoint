<?php

declare(strict_types=1);

namespace BibleGet\Tests\Live;

use PHPUnit\Framework\TestCase;

/**
 * Tests that query a deployed API (staging) with its real data.
 *
 * Skipped unless BIBLEGET_LIVE_URL is set to the base the routes hang off,
 * e.g. https://query.bibleget.io/dev (so /quote, /search/similar, ...).
 * Copyrighted editions' text never goes into these tests: they assert
 * structure and, where the words matter, an md5 of the returned text.
 *
 * When the staging API requires credentials (REQUIRE_CREDENTIALS), set
 * BIBLEGET_LIVE_API_KEY to a project key: it is sent as X-Api-Key. Without it
 * no header is sent, and redirects are never followed (they would carry the
 * key along). A rate-limited response (429) skips the test with that reason
 * instead of failing it.
 */
abstract class LiveTestCase extends TestCase
{
    private static string $baseUrl = '';

    protected function setUp(): void
    {
        $url = getenv('BIBLEGET_LIVE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('BIBLEGET_LIVE_URL is not set');
        }
        self::$baseUrl = rtrim($url, '/');
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    protected static function liveGet(string $path): array
    {
        $apiKey  = getenv('BIBLEGET_LIVE_API_KEY');
        $headers = "User-Agent: BibleGet-LiveTests/1.0\r\nAccept: application/json\r\n";
        if (is_string($apiKey) && $apiKey !== '') {
            $headers .= "X-Api-Key: {$apiKey}\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => $headers,
                'ignore_errors'   => true,
                'timeout'         => 30,
                // Never follow a redirect: PHP would resend X-Api-Key to
                // wherever it points, even another origin.
                'follow_location' => 0,
            ]
        ]);
        $raw     = file_get_contents(self::$baseUrl . $path, false, $context);
        $status  = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($status === 429) {
            self::markTestSkipped('Rate limited by the API: ' . $path);
        }
        $body = is_string($raw) ? json_decode($raw, true) : null;
        self::assertIsArray($body, "Not a JSON object from {$path} (HTTP {$status})");
        /** @var array<string, mixed> $body */
        return ['status' => $status, 'body' => $body];
    }

    /**
     * [chapter, verse, verse_label] of each result row: the label the edition
     * prints when it is not simply the number (migration 045).
     *
     * @param array<string, mixed> $body
     * @return list<array{int, int, ?string}>
     */
    protected static function printedLabels(array $body): array
    {
        $rows = [];
        foreach (is_array($body['results'] ?? null) ? $body['results'] : [] as $row) {
            self::assertIsArray($row);
            $label  = $row['verse_label'] ?? null;
            $rows[] = [(int) $row['chapter'], (int) $row['verse'], is_string($label) ? $label : null];
        }
        return $rows;
    }

    /**
     * The [chapter, verse, lettered label] of each result row, in order: the
     * label is the verse number with its printed letter ("5a", from
     * verse_segment, migration 046), null for an unlettered verse.
     *
     * @param array<string, mixed> $body
     * @return list<array{int, int, ?string}>
     */
    protected static function labels(array $body): array
    {
        $rows = [];
        foreach (is_array($body['results'] ?? null) ? $body['results'] : [] as $row) {
            self::assertIsArray($row);
            $segment = $row['verse_segment'] ?? null;
            $rows[]  = [(int) $row['chapter'], (int) $row['verse'], is_string($segment) ? ( (int) $row['verse'] ) . $segment : null];
        }
        return $rows;
    }
}
