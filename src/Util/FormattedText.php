<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

/**
 * Renders a verse's formatted `text` (the NABRE tag set, see the wiki's
 * Database Schema page) as HTML.
 *
 * The text is escaped first; only matched pairs of the known formatting tags
 * are then turned back into markup, so anything else in the stored text --
 * unknown tags, tags with attributes, an unmatched opening or closing tag --
 * stays inert and the surrounding HTML cannot be broken:
 *  - line/block tags (`<pof>`, `<po>`, `<poi>`, ... `<speaker>`) and `<sm>`
 *    (small capitals, L<sm>ORD</sm>) become `<span class="TAG">`;
 *  - `<i>` and `<sup>` are kept;
 *  - line breaks between lines become `<br>`.
 */
final class FormattedText
{
    private const SPAN_TAGS = 'po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|speaker|sm';
    private const KEPT_TAGS = 'i|sup';

    public static function toHtml(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Innermost pairs first, until nothing changes (pairs may nest: <po>L<sm>ORD</sm></po>).
        $pair  = '/&lt;(%s)&gt;((?:(?!&lt;\/?(?:%s)&gt;).)*?)&lt;\/\1&gt;/s';
        $spans = sprintf($pair, self::SPAN_TAGS, self::SPAN_TAGS . '|' . self::KEPT_TAGS);
        $kept  = sprintf($pair, self::KEPT_TAGS, self::SPAN_TAGS . '|' . self::KEPT_TAGS);
        do {
            $before = $html;
            $html   = (string) preg_replace($spans, '<span class="$1">$2</span>', $html);
            $html   = (string) preg_replace($kept, '<$1>$2</$1>', $html);
        } while ($html !== $before);

        return str_replace("\n", '<br>', $html);
    }
}
