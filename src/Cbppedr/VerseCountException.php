<?php

declare(strict_types=1);

namespace BibleGet\Api\Cbppedr;

/**
 * EntityRepository::replaceAll committed the catalog, but counting the verses of its entries (bpe_verse_count) then
 * failed: the cards show stale or no counts until the load is run again. The cause is the previous exception.
 */
final class VerseCountException extends \RuntimeException
{
    public function __construct(public readonly string $commit, \Throwable $previous)
    {
        parent::__construct("CBPPEDR $commit was loaded, but its verse counts were not: " . $previous->getMessage(), 0, $previous);
    }
}
