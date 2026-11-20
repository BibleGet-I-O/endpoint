<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Repositories;

use BibleGet\Api\Repositories\BtuRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;

/**
 * The review store of CBTUDR unit links (migration 053). Unit ids and refs are synthetic.
 */
class BtuRepositoryTest extends DatabaseTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/053-btu-review-store.sql';

    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnection()->exec((string) file_get_contents(self::MIGRATION));
        $this->getConnection()->exec('TRUNCATE btu_unit, btu_stretch, btu_link RESTART IDENTITY');
    }

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->getConnection()->exec('TRUNCATE btu_unit, btu_stretch, btu_link RESTART IDENTITY');
        }
        parent::tearDown();
    }

    private function repo(): BtuRepository
    {
        return new BtuRepository($this->getConnection());
    }

    /** Tobit 5 as a test edition drafts it: 5:1 by rule, 5:2-3 drafts in stretch 1 over units 5.2-5.4. */
    private function load(?float $c2 = 0.8, ?float $c3 = 0.4): void
    {
        $repo  = $this->repo();
        $units = [];
        foreach ([1, 2, 3, 4] as $i => $v) {
            $units[] = ['id' => 'btu:tb.5.' . $v, 'book' => 17, 'ord' => $i];
        }
        $units[] = ['id' => 'btu:tb.6.1', 'book' => 17, 'ord' => 4];
        $repo->upsertUnits($units);
        $repo->loadEdition('TEST', 17, [
            ['ref' => '5:1', 'ord' => 0, 'units' => ['btu:tb.5.1'], 'kind' => 'same', 'status' => 'rule'],
            ['ref' => '5:2', 'ord' => 1, 'units' => ['btu:tb.5.2', 'btu:tb.5.3'], 'kind' => 'corresponding', 'status' => 'draft', 'confidence' => $c2, 'stretch' => '1'],
            ['ref' => '5:3', 'ord' => 2, 'units' => ['btu:tb.5.4'], 'kind' => 'corresponding', 'status' => 'draft', 'confidence' => $c3, 'stretch' => '1'],
            ['ref' => '6:1', 'ord' => 3, 'units' => ['btu:tb.6.1'], 'kind' => 'same', 'status' => 'draft', 'confidence' => 0.9, 'stretch' => '2'],
        ], [
            ['id' => '1', 'units' => ['btu:tb.5.2', 'btu:tb.5.3', 'btu:tb.5.4']],
            ['id' => '2', 'units' => ['btu:tb.6.1']],
        ]);
    }

    private function idOf(string $ref): int
    {
        foreach ($this->repo()->chapter('TEST', 17, (int) explode(':', $ref)[0])['links'] as $l) {
            if ($l['ref'] === $ref) {
                return $l['id'];
            }
        }
        self::fail("no link $ref");
    }

    public function testLoadInsertsLinksAndStretches(): void
    {
        $this->load();
        $chapter = $this->repo()->chapter('TEST', 17, 5);
        self::assertSame(['5:1', '5:2', '5:3'], array_column($chapter['links'], 'ref'));
        self::assertSame(['btu:tb.5.2', 'btu:tb.5.3'], $chapter['links'][1]['units']);
        self::assertSame('draft', $chapter['links'][1]['status']);
        self::assertEqualsWithDelta(0.8, $chapter['links'][1]['confidence'], 1e-6);
        self::assertNull($chapter['links'][0]['confidence']);
        self::assertSame([['edition' => 'TEST', 'draft' => 3, 'reviewed' => 0, 'rule' => 1]], $this->repo()->editions());
    }

    public function testChapterReturnsTheUnitsOfItsStretches(): void
    {
        $this->load();
        $units = $this->repo()->chapter('TEST', 17, 5)['units'];
        self::assertSame(['btu:tb.5.2', 'btu:tb.5.3', 'btu:tb.5.4'], array_column($units, 'id'));
        self::assertSame(['5:2', '5:3', '5:4'], array_column($units, 'ref'));
        self::assertSame(['1', '1', '1'], array_column($units, 'stretch'));
    }

    public function testChaptersWithDraftsAreOrderedByLowestConfidence(): void
    {
        $this->load();
        self::assertSame([
            ['book' => 17, 'chapter' => 5, 'drafts' => 2, 'min_confidence' => 0.4],
            ['book' => 17, 'chapter' => 6, 'drafts' => 1, 'min_confidence' => 0.9],
        ], array_map(static fn (array $c) => array_merge($c, ['min_confidence' => round($c['min_confidence'], 4)]), $this->repo()->chaptersWithDrafts('TEST')));
    }

    public function testReviewRecordsTheReviewer(): void
    {
        $this->load();
        $link = $this->repo()->review($this->idOf('5:3'), ['action' => 'relink', 'units' => ['btu:tb.5.3', 'btu:tb.5.4']], 'user-1');
        self::assertSame('reviewed', $link['status']);
        self::assertSame(['btu:tb.5.3', 'btu:tb.5.4'], $link['units']);
        self::assertSame('user-1', $link['reviewed_by']);
        $none = $this->repo()->review($this->idOf('5:2'), ['action' => 'none'], 'user-1');
        self::assertSame([], $none['units']);
        $split = $this->repo()->review($this->idOf('6:1'), ['action' => 'split', 'note' => 'two parts'], 'user-1');
        self::assertSame('draft', $split['status']);
        self::assertSame('two parts', $split['note']);
        $kind = $this->repo()->review($this->idOf('6:1'), ['kind' => 'corresponding'], 'user-1');
        self::assertSame(['corresponding', 'draft'], [$kind['kind'], $kind['status']]);
    }

    public function testStretchUnitsAreThoseARelinkMayUse(): void
    {
        $this->load();
        self::assertSame(['btu:tb.5.2', 'btu:tb.5.3', 'btu:tb.5.4'], $this->repo()->stretchUnits('TEST', 17, '1'));
        self::assertSame([], $this->repo()->stretchUnits('TEST', 17, '9'));
    }

    public function testReloadKeepsReviewedLinks(): void
    {
        $this->load();
        $this->repo()->review($this->idOf('5:3'), ['action' => 'accept'], 'user-1');
        $this->load(0.7, 0.2);
        $chapter = $this->repo()->chapter('TEST', 17, 5);
        self::assertSame('reviewed', $chapter['links'][2]['status']);
        self::assertEqualsWithDelta(0.4, $chapter['links'][2]['confidence'], 1e-6);
        // The other draft took the new confidence.
        self::assertEqualsWithDelta(0.7, $chapter['links'][1]['confidence'], 1e-6);
    }

    public function testReloadReportsWhatItKept(): void
    {
        $this->load();
        $this->repo()->review($this->idOf('5:3'), ['action' => 'accept'], 'user-1');
        $result = $this->repo()->loadEdition('TEST', 17, [
            ['ref' => '5:3', 'ord' => 2, 'units' => ['btu:tb.5.2'], 'kind' => 'same', 'status' => 'draft', 'confidence' => 0.1, 'stretch' => '1'],
        ], []);
        self::assertSame(1, $result['kept']);
        self::assertSame(['btu:tb.5.4'], $this->repo()->link($this->idOf('5:3'))['units'] ?? null);
    }

    public function testAcceptChapterAcceptsOnlyThatChaptersDrafts(): void
    {
        $this->load();
        self::assertSame(2, $this->repo()->acceptChapter('TEST', 17, 5, 'user-1'));
        self::assertSame(['rule', 'reviewed', 'reviewed'], array_column($this->repo()->chapter('TEST', 17, 5)['links'], 'status'));
        self::assertSame(['draft'], array_column($this->repo()->chapter('TEST', 17, 6)['links'], 'status'));
    }

    public function testReloadKeepsLinksAReviewerTouched(): void
    {
        $this->load();
        $this->repo()->review($this->idOf('6:1'), ['kind' => 'corresponding'], 'user-1');
        $this->repo()->review($this->idOf('5:2'), ['action' => 'split', 'note' => 'two parts'], 'user-1');
        $this->load(0.7, 0.2);
        self::assertSame('corresponding', $this->repo()->link($this->idOf('6:1'))['kind'] ?? null);
        $split = $this->repo()->link($this->idOf('5:2'));
        self::assertSame(['two parts', 0.8], [$split['note'] ?? null, round((float) ( $split['confidence'] ?? 0 ), 4)]);
    }

    public function testAReviewedLinkKeepsItsStretchAcrossReloads(): void
    {
        $this->load();
        $this->repo()->review($this->idOf('5:3'), ['action' => 'accept'], 'user-1');
        // The next drafting run numbers its stretches anew: stretch 1 is now chapter 6's.
        $this->repo()->loadEdition('TEST', 17, [
            ['ref' => '5:2', 'ord' => 1, 'units' => ['btu:tb.5.2'], 'kind' => 'same', 'status' => 'rule'],
            ['ref' => '6:1', 'ord' => 3, 'units' => ['btu:tb.6.1'], 'kind' => 'same', 'status' => 'draft', 'confidence' => 0.9, 'stretch' => '1'],
        ], [['id' => '1', 'units' => ['btu:tb.6.1']]]);
        $link = $this->repo()->find($this->idOf('5:3'));
        self::assertNotNull($link);
        self::assertNotNull($link['link']['stretch']);
        self::assertSame(['btu:tb.5.2', 'btu:tb.5.3', 'btu:tb.5.4'], $this->repo()->stretchUnits('TEST', 17, (string) $link['link']['stretch']));
    }

    public function testAcceptChapterLeavesDraftsFlaggedForASplit(): void
    {
        $this->load();
        $this->repo()->review($this->idOf('5:2'), ['action' => 'split', 'note' => 'two parts'], 'user-1');
        self::assertSame(1, $this->repo()->acceptChapter('TEST', 17, 5, 'user-1'));
        self::assertSame('draft', $this->repo()->link($this->idOf('5:2'))['status'] ?? null);
    }

    public function testReviewOfALinkMadeByRuleIsRefused(): void
    {
        $this->load();
        self::assertNull($this->repo()->review($this->idOf('5:1'), ['action' => 'accept'], 'user-1'));
        self::assertSame('rule', $this->repo()->link($this->idOf('5:1'))['status'] ?? null);
    }

    public function testChaptersWithReviewedLinksForTheExport(): void
    {
        $this->load();
        $this->repo()->review($this->idOf('6:1'), ['action' => 'accept'], 'user-1');
        self::assertSame([['book' => 17, 'chapter' => 6, 'links' => 1]], $this->repo()->chaptersWithStatus('TEST', 'reviewed'));
        self::assertSame([['book' => 17, 'chapter' => 5, 'links' => 2]], $this->repo()->chaptersWithStatus('TEST', 'draft'));
    }

    public function testAReviewedLinkFromTheRegistryReplacesADraftEvenATouchedOne(): void
    {
        $this->load();
        // The reviewer flags 5:3 for a split; the split is made in the registry, which then has 5:3 reviewed.
        $this->repo()->review($this->idOf('5:3'), ['action' => 'split', 'note' => 'two parts'], 'user-1');
        $result = $this->repo()->loadEdition('TEST', 17, [
            ['ref' => '5:1', 'ord' => 0, 'units' => ['btu:tb.5.1'], 'kind' => 'same', 'status' => 'reviewed'],
            ['ref' => '5:3', 'ord' => 2, 'units' => ['btu:tb.5.4.1'], 'kind' => 'same', 'status' => 'reviewed'],
        ], []);
        $split  = $this->repo()->link($this->idOf('5:3'));
        self::assertNotNull($split);
        self::assertSame(['btu:tb.5.4.1'], $split['units']);
        self::assertSame(['reviewed', null, 'user-1', 'same'], [$split['status'], $split['note'], $split['reviewed_by'], $split['kind']]);
        // The rule link the registry now has reviewed is reviewed too.
        self::assertSame('reviewed', $this->repo()->link($this->idOf('5:1'))['status'] ?? null);
        self::assertSame(2, $result['updated']);
    }

    public function testALinkReviewedHereIsNotReplacedByTheRegistry(): void
    {
        $this->load();
        // Reviewed on staging after the registry's version: the staging decision is the newer one.
        $this->repo()->review($this->idOf('5:3'), ['action' => 'relink', 'units' => ['btu:tb.5.3']], 'user-1');
        $result = $this->repo()->loadEdition('TEST', 17, [
            ['ref' => '5:3', 'ord' => 2, 'units' => ['btu:tb.5.4'], 'kind' => 'corresponding', 'status' => 'reviewed'],
        ], []);
        self::assertSame(['btu:tb.5.3'], $this->repo()->link($this->idOf('5:3'))['units'] ?? null);
        self::assertSame(1, $result['kept']);
    }
}
