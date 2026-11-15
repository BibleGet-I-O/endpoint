<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Data;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Documented verse-structure edge cases (tests/fixtures/versification/cases.json)
 * checked against the committed per-edition snapshots of verse labels in
 * reading order (tests/fixtures/versification/<EDITION>.txt).
 *
 * The snapshots are generated from the database by
 * scripts/versification_snapshot.py, whose --check mode verifies that a
 * database still matches them. So a migration that moves a verse across a
 * chapter boundary, reorders verses or drops a lettered segment shows up
 * twice: as a snapshot diff, and here as a failing case once the snapshot is
 * regenerated. Cases whose data is known to be wrong carry "known_issue" and
 * are reported as incomplete until the data is fixed; when it is, the case
 * fails until the marker is removed.
 */
final class VersificationCasesTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../fixtures/versification';

    /** @var array<string, array<string, list<string>>> edition => chapter key => runs */
    private static array $snapshots = [];

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function cases(): array
    {
        $json = json_decode((string) file_get_contents(self::DIR . '/cases.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['cases']);
        $cases = [];
        foreach ($json['cases'] as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['id']);
            $entry = [];
            foreach ($case as $field => $value) {
                $entry[(string) $field] = $value;
            }
            $cases[$case['id']] = [$entry];
        }
        return $cases;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testCaseMatchesSnapshot(array $case): void
    {
        $this->assertContains($case['kind'] ?? null, ['chapter-boundary', 'chapter-count', 'split-verse', 'reordered', 'text-tradition']);
        $this->assertIsString($case['summary'] ?? null);
        $this->assertIsArray($case['expect'] ?? null);
        $this->assertNotEmpty($case['expect']);

        $mismatches = [];
        foreach ($case['expect'] as $edition => $chapters) {
            $this->assertIsString($edition);
            $this->assertIsArray($chapters);
            $snapshot = self::snapshot($edition);
            foreach ($chapters as $key => $expected) {
                $actual = isset($snapshot[$key]) ? implode(' | ', $snapshot[$key]) : null;
                if ($actual !== $expected) {
                    $mismatches[] = sprintf('%s %s: expected %s, snapshot has %s', $edition, $key, json_encode($expected), json_encode($actual));
                }
            }
        }

        $knownIssue = $case['known_issue'] ?? null;
        if (is_string($knownIssue)) {
            if ($mismatches !== []) {
                $this->markTestIncomplete("Known data defect ({$knownIssue}):\n" . implode("\n", $mismatches));
            }
            $this->fail("Case is fixed in the data: remove its known_issue ({$knownIssue}) from cases.json.");
        }
        $this->assertSame([], $mismatches, (string) $case['summary']);
    }

    public function testEverySnapshotLineIsWellFormed(): void
    {
        $files = glob(self::DIR . '/*.txt');
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            $this->assertNotFalse($lines);
            foreach ($lines as $n => $line) {
                if (str_starts_with($line, '#')) {
                    continue;
                }
                $this->assertMatchesRegularExpression(
                    '/^\d+ \d+(?: (?:HEBREW|GREEK))?: (?:\d+[a-z]*(?:-\d+)?)(?:,\d+[a-z]*(?:-\d+)?)*$/',
                    $line,
                    basename($file) . ':' . ( $n + 1 )
                );
            }
        }
    }

    /**
     * Chapter key ("29 10", "19 1 GREEK") => the label runs stored for it, in
     * order; a chapter stored in more than one place has several runs.
     *
     * @return array<string, list<string>>
     */
    private static function snapshot(string $edition): array
    {
        if (!isset(self::$snapshots[$edition])) {
            $path = self::DIR . '/' . $edition . '.txt';
            self::assertFileExists($path);
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($lines);
            $map = [];
            foreach ($lines as $line) {
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                [$key, $labels] = explode(': ', $line, 2);
                $map[$key][]    = $labels;
            }
            self::$snapshots[$edition] = $map;
        }
        return self::$snapshots[$edition];
    }
}
