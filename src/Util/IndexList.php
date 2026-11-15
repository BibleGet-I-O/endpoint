<?php

declare(strict_types=1);

namespace BibleGet\Api\Util;

use Psr\Log\LoggerInterface;

/**
 * Parses a comma-separated list of an `_idx` table (`verses_last`, `verses_count`).
 *
 * Every token should be a whole number. A token that isn't (NABRE once stored
 * `12:1` as the last verse of Wis 11) would silently become a wrong bound via
 * intval, so it is logged as an error while the list is still returned.
 */
final class IndexList
{
    /**
     * @param string $context Where the list comes from, for the log (e.g. "NABRE_idx book 27").
     * @return list<int>
     */
    public static function parse(string $csv, LoggerInterface $logger, string $context): array
    {
        $values = [];
        foreach (explode(',', $csv) as $token) {
            if (preg_match('/^\d+$/', $token) !== 1) {
                $logger->error('Non-integer token "' . $token . '" in ' . $context);
            }
            $values[] = (int) $token;
        }
        return $values;
    }
}
