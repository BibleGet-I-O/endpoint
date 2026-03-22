<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Ast;

final class VerseRef
{
    /**
     * @param int $position Byte offset in the original input string for error reporting
     */
    public function __construct(
        public readonly int $book,
        public readonly int $chapter,
        public readonly ?int $alternateChapter = null,
        public readonly ?int $verse = null,
        public readonly ?string $partialSuffix = null,
        public readonly int $position = -1,
    ) {
    }
}
