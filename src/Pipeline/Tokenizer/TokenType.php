<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Tokenizer;

enum TokenType: string
{
    // ── Identifiers ──────────────────────────────────────────────
    case BOOK_NUMERIC_PREFIX = 'BOOK_NUMERIC_PREFIX';
    case BOOK_NAME           = 'BOOK_NAME';

    // ── Numeric tokens (resolved by context during tokenization) ─
    case CHAPTER_NUMBER       = 'CHAPTER_NUMBER';
    case VERSE_NUMBER         = 'VERSE_NUMBER';
    case PARTIAL_VERSE_SUFFIX = 'PARTIAL_VERSE_SUFFIX';

    // ── Psalm dual numbering ────────────────────────────────────
    case OPEN_PARENTHESIS  = 'OPEN_PARENTHESIS';
    case ALTERNATE_CHAPTER = 'ALTERNATE_CHAPTER';
    case CLOSE_PARENTHESIS = 'CLOSE_PARENTHESIS';

    // ── Structural separators (semantic, post-normalization) ────
    case CHAPTER_VERSE_SEPARATOR  = 'CHAPTER_VERSE_SEPARATOR';
    case EXPLICIT_VERSE_SEPARATOR = 'EXPLICIT_VERSE_SEPARATOR';
    case RANGE_SEPARATOR          = 'RANGE_SEPARATOR';
    case QUERY_SEPARATOR          = 'QUERY_SEPARATOR';

    // ── Control ──────────────────────────────────────────────────
    case EOF = 'EOF';
}
