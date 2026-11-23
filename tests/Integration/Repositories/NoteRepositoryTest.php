<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Repositories;

use BibleGet\Api\Repositories\NoteRepository;
use BibleGet\Tests\Integration\DatabaseTestCase;
use BibleGet\Tests\Support\SyntheticNotes;

final class NoteRepositoryTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        $this->getConnection()->exec("DELETE FROM note WHERE edition IN ('TEST1', 'TEST2')");
        parent::tearDown();
    }

    public function testAnchorsAreThoseOfTheEditionAndChapters(): void
    {
        $pdo = $this->getConnection();
        SyntheticNotes::add($pdo, 'TEST1', '01001001-1', 'footnote', '*', [[1, 1, 1, null, null, null, 'text', 5]]);
        SyntheticNotes::add($pdo, 'TEST1', '01003001-1', 'crossref', 'a', [[1, 3, 1, 'a', 'GREEK', 2, 'title1', null]]);
        SyntheticNotes::add($pdo, 'TEST1', '01004001-1', 'footnote', '*', [[1, 4, 1]]);
        SyntheticNotes::add($pdo, 'TEST2', '01001001-1', 'footnote', '*', [[1, 1, 1]]);

        $anchors = ( new NoteRepository($pdo) )->anchors('TEST1', 1, 1, 3);

        self::assertCount(2, $anchors);
        $byId = array_column($anchors, null, 'id');
        self::assertSame(
            ['kind' => 'footnote', 'marker' => '*', 'chapter' => 1, 'verse' => 1, 'verse_segment' => null, 'text_tradition' => null, 'printed_chapter' => null, 'field' => 'text', 'position' => 5],
            array_diff_key($byId['TEST1:01001001-1'], ['note_id' => 0, 'id' => 0])
        );
        self::assertSame(
            ['verse_segment' => 'a', 'text_tradition' => 'GREEK', 'printed_chapter' => 2, 'field' => 'title1', 'position' => null],
            array_intersect_key($byId['TEST1:01003001-1'], array_flip(['verse_segment', 'text_tradition', 'printed_chapter', 'field', 'position']))
        );
        self::assertIsInt($byId['TEST1:01003001-1']['note_id']);
    }

    public function testBodiesCarryTheirCitationsInOrder(): void
    {
        $pdo = $this->getConnection();
        $id  = SyntheticNotes::add(
            $pdo,
            'TEST1',
            '01001001-1',
            'crossref',
            'a',
            [[1, 1, 1]],
            [[43, 1, 1, 0, 7, 43, 1, 3], [1, 2, null, 9, 12]],
            'Jn 1:1-3; Gn 2.',
            '1:1',
            null
        );

        $bodies = ( new NoteRepository($pdo) )->bodies([$id]);

        self::assertSame([$id], array_keys($bodies));
        self::assertSame(
            [
                'kind'   => 'crossref',
                'marker' => 'a',
                'label'  => '1:1',
                'title'  => null,
                'body'   => 'Jn 1:1-3; Gn 2.',
                'refs'   => [
                    ['start' => ['book' => 43, 'chapter' => 1, 'verse' => 1, 'segment' => null, 'printed_chapter' => null], 'end' => ['book' => 43, 'chapter' => 1, 'verse' => 3], 'span' => [0, 7]],
                    ['start' => ['book' => 1, 'chapter' => 2, 'verse' => null, 'segment' => null, 'printed_chapter' => null], 'end' => null, 'span' => [9, 12]],
                ],
            ],
            $bodies[$id]
        );
        self::assertSame([], ( new NoteRepository($pdo) )->bodies([]));
    }
}
