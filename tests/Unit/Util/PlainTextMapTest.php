<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Util\PlainTextMap;
use PHPUnit\Framework\TestCase;

class PlainTextMapTest extends TestCase
{
    public function testDropsVariantSigns(): void
    {
        $map = PlainTextMap::of('⌈et Petrus⌉ dixit');
        $this->assertSame('et Petrus dixit', $map->plain());
        $this->assertSame([4, 10], $map->toText(3, 9));
        // A mark opens before a sign that precedes its first character.
        $this->assertSame([0, 3], $map->toText(0, 2));
    }

    public function testDropsInlineTags(): void
    {
        $map = PlainTextMap::of('et <i>Pe</i>trus');
        $this->assertSame('et Petrus', $map->plain());
        // The whole `<i>Pe</i>trus` (code points 3 to 16).
        $this->assertSame([3, 16], $map->toText(3, 9));
        $this->assertSame('LORD a', PlainTextMap::of('L<sm>ORD</sm> <sup>a</sup>')->plain());
    }

    public function testLineTagsBecomeSpaces(): void
    {
        $map = PlainTextMap::of('<pof>Dixit</pof><po>Petrus</po>');
        $this->assertSame('Dixit Petrus', $map->plain());
        $this->assertSame([20, 26], $map->toText(6, 12));
        $this->assertSame('a b c', PlainTextMap::of('a<br/>b<BR >c')->plain());
        $this->assertSame('a b', PlainTextMap::of("<speaker>a</speaker>\n<pr>b</pr>")->plain());
        // Other tags and entities stay as they are.
        $this->assertSame('<x>a</x> &amp; b', PlainTextMap::of('<x>a</x> &amp; b')->plain());
    }

    public function testCollapsesWhitespaceAndTrims(): void
    {
        $map = PlainTextMap::of("  et \n\t Petrus  ");
        $this->assertSame('et Petrus', $map->plain());
        // The collapsed space maps to the whole run, from its first character.
        $this->assertSame([4, 8], $map->toText(2, 3));
        $this->assertSame([8, 14], $map->toText(3, 9));
    }

    public function testCountsUtf16In(): void
    {
        $map = PlainTextMap::of("\u{1D504} et Petrus");
        $this->assertSame("\u{1D504} et Petrus", $map->plain());
        // The astral letter is two UTF-16 units, one code point.
        $this->assertSame([5, 11], $map->toText(6, 12));
        $this->assertSame([0, 1], $map->toText(0, 2));
        $this->assertNull($map->toText(6, 13));
    }

    public function testSnapsOutOfATag(): void
    {
        $this->assertSame([0, 13], PlainTextMap::of('<i>Petrus</i> dixit')->toText(0, 6));
        // An open tag before an earlier word is not swallowed.
        $this->assertSame([6, 12], PlainTextMap::of('<i>et Petrus</i>')->toText(3, 9));
    }

    public function testAnUnmatchedCloserDoesNotCancelALaterOpener(): void
    {
        // The first </i> closes a tag opened before the span; the second <i> opens inside it, so its </i> is taken.
        $this->assertSame([6, 23], PlainTextMap::of('<i>et Pe</i><i>trus</i>')->toText(3, 9));
        $this->assertSame([0, 13], PlainTextMap::of('<i>Petrus</i>')->toText(0, 6));
    }

    public function testOutOfRangeIsNull(): void
    {
        $map = PlainTextMap::of('et Petrus');
        $this->assertNull($map->toText(-1, 2));
        $this->assertNull($map->toText(0, 10));
        $this->assertNull($map->toText(3, 3));
        $this->assertNull($map->toText(4, 3));
        $this->assertSame([0, 9], $map->toText(0, 9));
    }
}
