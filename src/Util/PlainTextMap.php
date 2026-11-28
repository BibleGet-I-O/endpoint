<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

use InvalidArgumentException;

/**
 * A verse's plain text (`text_plain`) together with where each of its
 * characters comes from in the formatted `text`.
 *
 * `plain()` equals the SQL function `bibleget_plain_text(text)` (migration
 * 034), whose rules are applied here in the same order:
 *  1. the variant signs ⌈ ⌉ ⊥ are removed;
 *  2. the inline tags `<sm>`, `<i>`, `<sup>` (and their closers) are removed;
 *  3. the line/block tags (`<po>` ... `<bql>`, `<pr>`, `<speaker>`) become a space;
 *  4. `<br>` / `<br/>` (any case) becomes a space;
 *  5. each run of whitespace (space, \t, \n, \r, \f, \v) becomes one space;
 *  6. spaces are trimmed at both ends.
 * Anything else (other tags, entities such as `&amp;`) stays as it is.
 *
 * `toText()` converts a span of the plain text, in UTF-16 code units (how
 * CBPPEDR records a mention), to a span of `text` in code points (what the
 * website's renderer counts), end exclusive:
 *  - it starts at the source of the span's first plain character and ends just
 *    after the source of its last one; a space that stands for a collapsed
 *    whitespace run, a line tag or a `<br>` covers all of it, from its first
 *    character;
 *  - the start snaps back over removed opening inline tags and variant signs
 *    immediately before it, so a mark opens before a tag that wraps the word
 *    (`et <i>Pe</i>trus` -> `<i>Pe</i>trus`), but never over a closing tag
 *    or anything that yields a plain character;
 *  - the end snaps forward over removed closing inline tags immediately after
 *    it whose opening tag is inside the span (`<i>Petrus</i>`), so the span's
 *    inline tags stay balanced where that is easy; a closer of a tag opened
 *    before the span is left out (`<i>et Petrus</i>` -> `Petrus`), and does
 *    not cancel a tag opened inside it (`<i>et Pe</i><i>trus</i>` ->
 *    `Pe</i><i>trus</i>`).
 * A UTF-16 offset inside an astral character's surrogate pair counts as that
 * character.
 */
final class PlainTextMap
{
    private const string SPACE = '[ \t\n\r\f\x0B]';

    private const int SIGN  = 1;
    private const int OPEN  = 2;
    private const int CLOSE = 3;

    /**
     * @param string                $plain
     * @param list<array{int, int}> $sources  for each plain code point, the [start, end) code points of `text`
     *                                        it comes from
     * @param list<int>             $unitChar for each UTF-16 code unit of the plain text, its code point index
     * @param array<int, int>       $removed  for each code point of `text` that was removed, what it belonged to
     *                                        (SIGN, OPEN or CLOSE)
     * @param array<int, array{int, string, bool}> $tags for each removed inline tag, keyed by its first code
     *                                        point: [end, name, is closing]
     */
    private function __construct(
        private readonly string $plain,
        private readonly array $sources,
        private readonly array $unitChar,
        private readonly array $removed,
        private readonly array $tags,
    ) {
    }

    public static function of(string $text): self
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('The text is not valid UTF-8');
        }

        // Each character still in the text: [code point, source start, source end].
        $chars = [];
        foreach (mb_str_split($text) as $i => $cp) {
            $chars[] = [$cp, $i, $i + 1];
        }
        $removed = [];
        $tags    = [];

        $chars = self::replace($chars, '/[⌈⌉⊥]/u', null, static function (array $match, string $source) use (&$removed): void {
            $removed[$match[0][1]] = self::SIGN;
        });
        $chars = self::replace(
            $chars,
            '/<(\/?)(sm|i|sup)>/u',
            null,
            static function (array $match, string $source) use (&$removed, &$tags): void {
                $close        = str_starts_with($source, '</');
                $start        = $match[0][1];
                $end          = $match[count($match) - 1][2];
                $tags[$start] = [$end, trim($source, '</>'), $close];
                foreach ($match as $char) {
                    $removed[$char[1]] = $close ? self::CLOSE : self::OPEN;
                }
            }
        );
        $chars = self::replace($chars, '/<\/?(po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|speaker)>/u', ' ');
        $chars = self::replace($chars, '/<br' . self::SPACE . '*\/?>/iu', ' ');
        $chars = self::replace($chars, '/' . self::SPACE . '+/u', ' ');
        $chars = self::trim($chars);

        $plain    = '';
        $sources  = [];
        $unitChar = [];
        foreach ($chars as $index => [$cp, $start, $end]) {
            $plain    .= $cp;
            $sources[] = [$start, $end];
            $units     = mb_ord($cp, 'UTF-8') > 0xFFFF ? 2 : 1;
            for ($u = 0; $u < $units; $u++) {
                $unitChar[] = $index;
            }
        }

        return new self($plain, $sources, $unitChar, $removed, $tags);
    }

    public function plain(): string
    {
        return $this->plain;
    }

    /**
     * @return array{0: int, 1: int}|null [start, end) in code points of `text`, or null when the span is out of
     *                                    range or empty
     */
    public function toText(int $startUtf16, int $endUtf16): ?array
    {
        if ($startUtf16 < 0 || $endUtf16 > count($this->unitChar) || $startUtf16 >= $endUtf16) {
            return null;
        }
        $start = $this->sources[$this->unitChar[$startUtf16]][0];
        $end   = $this->sources[$this->unitChar[$endUtf16 - 1]][1];

        while ($start > 0 && in_array($this->removed[$start - 1] ?? 0, [self::SIGN, self::OPEN], true)) {
            $start--;
        }

        return [$start, $this->snapEnd($start, $end)];
    }

    private function snapEnd(int $start, int $end): int
    {
        // Inline tags opened inside the span and not yet closed there, by name: a closer only closes an opener of
        // the span (the tags are in text order), and a closer of a tag opened before the span is ignored.
        $open = [];
        foreach ($this->tags as $at => [, $name, $close]) {
            if ($at < $start || $at >= $end) {
                continue;
            }
            if (!$close) {
                $open[$name] = ( $open[$name] ?? 0 ) + 1;
            } elseif (( $open[$name] ?? 0 ) > 0) {
                $open[$name]--;
            }
        }
        while (isset($this->tags[$end]) && $this->tags[$end][2] && ( $open[$this->tags[$end][1]] ?? 0 ) > 0) {
            $open[$this->tags[$end][1]]--;
            $end = $this->tags[$end][0];
        }

        return $end;
    }

    /**
     * Applies one regex replacement to the characters, keeping their sources.
     *
     * A match is removed (`$replacement` null; `$onRemove` is told about it) or
     * becomes one character that covers the whole match.
     *
     * @param list<array{string, int, int}> $chars
     * @param (callable(list<array{string, int, int}>, string): void)|null $onRemove
     * @return list<array{string, int, int}>
     */
    private static function replace(array $chars, string $pattern, ?string $replacement, ?callable $onRemove = null): array
    {
        $string = '';
        $byChar = [];
        foreach ($chars as $index => [$cp]) {
            $byChar[strlen($string)] = $index;
            $string                 .= $cp;
        }
        if (preg_match_all($pattern, $string, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new InvalidArgumentException('The text could not be matched');
        }

        $out  = [];
        $next = 0;
        foreach ($matches[0] as [$source, $byte]) {
            $first = $byChar[$byte];
            $last  = $byChar[$byte + strlen($source) - 1] ?? self::lastCharAt($byChar, $byte + strlen($source) - 1);
            array_push($out, ...array_slice($chars, $next, $first - $next));
            $match = array_slice($chars, $first, $last - $first + 1);
            if ($replacement === null) {
                if ($onRemove !== null) {
                    $onRemove($match, $source);
                }
            } else {
                $out[] = [$replacement, $match[0][1], $match[count($match) - 1][2]];
            }
            $next = $last + 1;
        }
        array_push($out, ...array_slice($chars, $next));

        return $out;
    }

    /**
     * The index of the character whose bytes include `$byte`.
     *
     * @param array<int, int> $byChar character index by first byte
     */
    private static function lastCharAt(array $byChar, int $byte): int
    {
        while (!isset($byChar[$byte])) {
            $byte--;
        }

        return $byChar[$byte];
    }

    /**
     * btrim(): drops spaces at both ends.
     *
     * @param list<array{string, int, int}> $chars
     * @return list<array{string, int, int}>
     */
    private static function trim(array $chars): array
    {
        $from = 0;
        $to   = count($chars);
        while ($from < $to && $chars[$from][0] === ' ') {
            $from++;
        }
        while ($to > $from && $chars[$to - 1][0] === ' ') {
            $to--;
        }

        return array_slice($chars, $from, $to - $from);
    }
}
