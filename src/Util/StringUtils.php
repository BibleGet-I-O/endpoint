<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

/**
 * Unicode-aware string utilities shared across handlers and pipeline classes.
 */
class StringUtils
{
    /**
     * Check whether the string's script distinguishes upper and lower case.
     */
    public static function stringWithUpperAndLowerCaseVariants(string $str): bool
    {
        return (bool) preg_match('/\p{L&}/u', $str);
    }

    /**
     * Convert a string to proper case (first cased letter upper, rest lower),
     * handling Unicode scripts correctly.
     */
    public static function toProperCase(string $txt): string
    {
        if (self::stringWithUpperAndLowerCaseVariants($txt) === false) {
            return $txt;
        }
        preg_match('/\p{L&}/u', $txt, $mList, PREG_OFFSET_CAPTURE);
        if ($mList) {
            $byteOffset = $mList[0][1];
            $charOffset = mb_strlen(substr($txt, 0, $byteOffset), 'UTF-8');
            $chr = mb_substr($txt, $charOffset, 1, 'UTF-8');
            $post = mb_substr($txt, $charOffset + 1, null, 'UTF-8');
            return mb_substr($txt, 0, $charOffset, 'UTF-8') . mb_strtoupper($chr, 'UTF-8') . mb_strtolower($post, 'UTF-8');
        }
        return $txt;
    }

    /**
     * Normalize a Bible book name: strip whitespace and convert to proper case.
     */
    public static function normalizeBibleBook(string $str): string
    {
        return self::toProperCase(preg_replace('/\s+/', '', trim($str)) ?? trim($str));
    }
}
