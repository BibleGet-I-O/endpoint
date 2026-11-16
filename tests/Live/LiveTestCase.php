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
 * The API rate-limits identical queries from one IP (more than 10 in two
 * days). A rate-limited response skips the test with that reason instead of
 * failing it; a fresh CI runner sends each query once.
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
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: BibleGet-LiveTests/1.0\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
                'timeout'       => 30,
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
        self::assertIsArray($body, 'Not a JSON object from ' . $path);
        /** @var array<string, mixed> $body */
        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $err) {
            if (is_array($err) && ( $err['errNum'] ?? null ) === 10) {
                self::markTestSkipped('Rate limited by the API: ' . $path);
            }
        }
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
     * The [chapter, verse, verseequiv] of each result row, in order.
     *
     * @param array<string, mixed> $body
     * @return list<array{int, int, ?string}>
     */
    protected static function labels(array $body): array
    {
        $rows = [];
        foreach (is_array($body['results'] ?? null) ? $body['results'] : [] as $row) {
            self::assertIsArray($row);
            $equiv  = $row['verseequiv'] ?? null;
            $rows[] = [(int) $row['chapter'], (int) $row['verse'], is_string($equiv) ? $equiv : null];
        }
        return $rows;
    }
}
