<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Ast;

final class BibleQuery
{
    /**
     * @param array<VerseRef|VerseRange> $segments Non-consecutive segments joined by "."
     */
    public function __construct(
        public readonly int $book,
        public readonly array $segments,
    ) {
    }
}
