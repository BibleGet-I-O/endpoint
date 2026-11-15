<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Util\FormattedText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormattedTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'plain prose'               => ['In the beginning.', 'In the beginning.'],
            'poetic lines'              => [
                "<pof>The L<sm>ORD</sm> is my shepherd;</pof>\n<poi>there is nothing I lack.</poi>",
                '<span class="pof">The L<span class="sm">ORD</span> is my shepherd;</span><br>'
                . '<span class="poi">there is nothing I lack.</span>',
            ],
            'untagged lead-in line'     => ["A psalm of David.\n<pof>The Lord</pof>", 'A psalm of David.<br><span class="pof">The Lord</span>'],
            'italics and superscript'   => ['<i>Sela</i> 1<sup>st</sup>', '<i>Sela</i> 1<sup>st</sup>'],
            'speaker label'             => ['<pof><speaker>W</speaker> Let him kiss me</pof>', '<span class="pof"><span class="speaker">W</span> Let him kiss me</span>'],
            'quotes and ampersands'     => ['"Va\' in pace" & more', '&quot;Va&#039; in pace&quot; &amp; more'],
            'unknown tags stay inert'   => ['<script>alert(1)</script><b>x</b>', '&lt;script&gt;alert(1)&lt;/script&gt;&lt;b&gt;x&lt;/b&gt;'],
            'tag with attributes inert' => ['<po onclick="x">a</po>', '&lt;po onclick=&quot;x&quot;&gt;a&lt;/po&gt;'],
            'unmatched closing inert'   => ['a</po></span>b', 'a&lt;/po&gt;&lt;/span&gt;b'],
            'unmatched opening inert'   => ['<pof>a', '&lt;pof&gt;a'],
            'italics inside a line'     => ['<po>the <i>Law</i> of the L<sm>ORD</sm></po>', '<span class="po">the <i>Law</i> of the L<span class="sm">ORD</span></span>'],
        ];
    }

    #[DataProvider('cases')]
    public function testToHtml(string $text, string $expected): void
    {
        self::assertSame($expected, FormattedText::toHtml($text));
    }
}
