<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Ast;

final class VerseRange
{
    public function __construct(
        public readonly VerseRef $from,
        public readonly VerseRef $to,
    ) {
    }
}
