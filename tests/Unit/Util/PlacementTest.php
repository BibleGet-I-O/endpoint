<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Util\Placement;
use PHPUnit\Framework\TestCase;

final class PlacementTest extends TestCase
{
    /**
     * Search rows carry every verse-identity field a quote row carries (046).
     */
    public function testVerseFieldsCarryAllIdentityFields(): void
    {
        $fields = Placement::verseFields([
            'chapter'         => 119,
            'verse'           => 1,
            'verse_segment'   => null,
            'alt_ref'         => '(118,1)',
            'acrostic'        => 'Aleph',
            'text_tradition'  => null,
            'printed_chapter' => null,
            'verse_label'     => null,
            'omitted'         => false,
        ]);
        self::assertSame('Aleph', $fields['acrostic']);
        self::assertArrayHasKey('text_tradition', $fields);
        self::assertNull($fields['text_tradition']);
        self::assertSame('(118,1)', $fields['alt_ref']);

        self::assertSame('GREEK', Placement::verseFields(['text_tradition' => 'GREEK'])['text_tradition']);
    }
}
