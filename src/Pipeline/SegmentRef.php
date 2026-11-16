<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

/**
 * The verses one compiled SQL query asks for, when they lie in one chapter
 * (a single verse or a verse range): what a VERSE_OMITTED notice reports.
 * Whole-chapter and cross-chapter segments have none.
 */
final class SegmentRef
{
    public function __construct(
        public readonly int $book,
        public readonly int $chapter,
        public readonly int $verseFrom,
        public readonly int $verseTo,
        /** For display only: the book as the user wrote it, in the user's notation. */
        public readonly string $reference,
    ) {
    }
}
