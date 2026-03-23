<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Tokenizer;

use BibleGet\Api\Util\StringUtils;

/**
 * Converts a normalized Bible reference string into a stream of typed tokens.
 *
 * Operates on a single query (already split by ";") after notation normalization
 * (English→European), so ":" has already been replaced by ",".
 *
 * The tokenizer resolves CHAPTER_NUMBER vs VERSE_NUMBER based on a minimal
 * context flag (chapter_level vs verse_level) — see the plan document for
 * the full classification table.
 */
final class ReferenceTokenizer
{
    private string $input;
    private int $length;
    private int $pos = 0;

    /** @var Token[] */
    private array $tokens = [];

    private bool $atVerseLevel = false;

    /**
     * @return Token[]
     */
    public function tokenize(string $input): array
    {
        $this->input        = $input;
        $this->length       = strlen($input);
        $this->pos          = 0;
        $this->tokens       = [];
        $this->atVerseLevel = false;

        while ($this->pos < $this->length) {
            $this->readNextToken();
        }

        $this->tokens[] = new Token(TokenType::EOF, '', $this->pos);

        return $this->tokens;
    }

    private function readNextToken(): void
    {
        $ch = $this->input[$this->pos];

        if ($ch === ',') {
            $this->tokens[] = new Token(TokenType::CHAPTER_VERSE_SEPARATOR, ',', $this->pos);
            $this->pos++;
            $this->atVerseLevel = true;
            return;
        }

        if ($ch === '.') {
            $this->tokens[] = new Token(TokenType::EXPLICIT_VERSE_SEPARATOR, '.', $this->pos);
            $this->pos++;
            $this->atVerseLevel = true;
            return;
        }

        if ($ch === '-') {
            $this->tokens[] = new Token(TokenType::RANGE_SEPARATOR, '-', $this->pos);
            $this->pos++;
            // atVerseLevel is inherited — don't change it
            return;
        }

        if ($ch === ';') {
            $this->tokens[] = new Token(TokenType::QUERY_SEPARATOR, ';', $this->pos);
            $this->pos++;
            $this->atVerseLevel = false;
            return;
        }

        if ($ch === '(') {
            $this->tokens[] = new Token(TokenType::OPEN_PARENTHESIS, '(', $this->pos);
            $this->pos++;
            $this->readAlternateChapter();
            return;
        }

        if ($this->isDigit($ch)) {
            $this->readNumber();
            return;
        }

        // Must be a book name (possibly preceded by a numeric prefix already consumed,
        // or starting fresh). Try to read a book indicator.
        $this->readBookIndicator();
    }

    private function readBookIndicator(): void
    {
        $startPos = $this->pos;

        // Check for numeric prefix (1-4 before a book name)
        if (
            $this->pos < $this->length
            && $this->input[$this->pos] >= '1'
            && $this->input[$this->pos] <= '4'
            && $this->pos + 1 < $this->length
            && $this->isLetterStart($this->pos + 1)
        ) {
            $this->tokens[] = new Token(TokenType::BOOK_NUMERIC_PREFIX, $this->input[$this->pos], $this->pos);
            $this->pos++;
        }

        // Read the book name: Unicode letters (possibly with combining marks)
        $bookStart = $this->pos;
        $bookName  = $this->readUnicodeWord();

        if ($bookName === '') {
            // Unexpected character — skip it to avoid infinite loop
            $this->pos++;
            return;
        }

        $this->tokens[]     = new Token(TokenType::BOOK_NAME, $bookName, $bookStart);
        $this->atVerseLevel = false;
    }

    private function readUnicodeWord(): string
    {
        $start = $this->pos;
        // Match Unicode letters and combining marks: (\p{L}\p{M}*)+
        if (preg_match('/\G(\p{L}\p{M}*)+/u', $this->input, $matches, 0, $this->pos)) {
            $this->pos += strlen($matches[0]);
            return $matches[0];
        }
        return '';
    }

    private function readNumber(): void
    {
        $startPos = $this->pos;
        $numStr   = '';

        while ($this->pos < $this->length && $this->isDigit($this->input[$this->pos])) {
            $numStr .= $this->input[$this->pos];
            $this->pos++;
        }

        // Determine if this is a book numeric prefix: digit 1-4 followed by letter, no tokens yet or last was QUERY_SEPARATOR
        if ($this->isBookNumericPrefix($numStr)) {
            $this->tokens[] = new Token(TokenType::BOOK_NUMERIC_PREFIX, $numStr, $startPos);
            // Now read the book name
            $bookStart = $this->pos;
            $bookName  = $this->readUnicodeWord();
            if ($bookName !== '') {
                $this->tokens[]     = new Token(TokenType::BOOK_NAME, $bookName, $bookStart);
                $this->atVerseLevel = false;
            }
            return;
        }

        // Classify as CHAPTER_NUMBER or VERSE_NUMBER based on context
        $type           = $this->classifyNumber();
        $this->tokens[] = new Token($type, $numStr, $startPos);

        // After emitting a number, check for partial verse suffix (lowercase letter immediately after)
        if ($type === TokenType::VERSE_NUMBER) {
            $this->readPartialVerseSuffix();
        }
    }

    private function isBookNumericPrefix(string $numStr): bool
    {
        // Must be a single digit 1-4
        if (strlen($numStr) !== 1 || $numStr < '1' || $numStr > '4') {
            return false;
        }

        // Must be followed by a letter (start of book name)
        if ($this->pos >= $this->length || !$this->isLetterStart($this->pos)) {
            return false;
        }

        // Must be at the start of a query: either no tokens yet, or last token is QUERY_SEPARATOR or BOOK_NAME-adjacent
        if (empty($this->tokens)) {
            return true;
        }

        $lastToken = $this->tokens[count($this->tokens) - 1];
        return $lastToken->type === TokenType::QUERY_SEPARATOR;
    }

    private function classifyNumber(): TokenType
    {
        // Look at the preceding token
        $lastToken = $this->lastMeaningfulToken();

        // After a book name → always CHAPTER_NUMBER
        if ($lastToken !== null && $lastToken->type === TokenType::BOOK_NAME) {
            return TokenType::CHAPTER_NUMBER;
        }

        // After CHAPTER_VERSE_SEPARATOR → always VERSE_NUMBER
        if ($lastToken !== null && $lastToken->type === TokenType::CHAPTER_VERSE_SEPARATOR) {
            return TokenType::VERSE_NUMBER;
        }

        // After EXPLICIT_VERSE_SEPARATOR → always VERSE_NUMBER
        if ($lastToken !== null && $lastToken->type === TokenType::EXPLICIT_VERSE_SEPARATOR) {
            return TokenType::VERSE_NUMBER;
        }

        // After CLOSE_PARENTHESIS (dual Psalm numbering) — treat like after CHAPTER_NUMBER
        // Lookahead determines: if followed by CHAPTER_VERSE_SEPARATOR → CHAPTER_NUMBER (already is)
        // This case shouldn't produce a number directly, but handle defensively
        if ($lastToken !== null && $lastToken->type === TokenType::CLOSE_PARENTHESIS) {
            return $this->classifyByLookahead();
        }

        // After RANGE_SEPARATOR — lookahead determines classification
        if ($lastToken !== null && $lastToken->type === TokenType::RANGE_SEPARATOR) {
            return $this->classifyByLookahead();
        }

        // Default: use lookahead
        return $this->classifyByLookahead();
    }

    private function classifyByLookahead(): TokenType
    {
        // If the next character is ',' → this number is a CHAPTER_NUMBER
        if ($this->pos < $this->length && $this->input[$this->pos] === ',') {
            return TokenType::CHAPTER_NUMBER;
        }

        // If the next character is '(' → this number is a CHAPTER_NUMBER (Psalm dual numbering)
        if ($this->pos < $this->length && $this->input[$this->pos] === '(') {
            return TokenType::CHAPTER_NUMBER;
        }

        // Otherwise, inherit from context
        return $this->atVerseLevel ? TokenType::VERSE_NUMBER : TokenType::CHAPTER_NUMBER;
    }

    private function readPartialVerseSuffix(): void
    {
        if ($this->pos >= $this->length) {
            return;
        }

        // A partial verse suffix is a single lowercase ASCII letter immediately after a verse number,
        // but NOT if it starts a book name (i.e., not if followed by more letters)
        $ch = $this->input[$this->pos];
        if ($ch >= 'a' && $ch <= 'z') {
            // Check the character after this one — if it's also a letter, this is likely
            // a book name, not a partial verse suffix
            $nextPos = $this->pos + 1;
            if ($nextPos < $this->length && $this->isLetterStart($nextPos)) {
                return;
            }
            $this->tokens[] = new Token(TokenType::PARTIAL_VERSE_SUFFIX, $ch, $this->pos);
            $this->pos++;
        }
    }

    private function readAlternateChapter(): void
    {
        // We already consumed '(' — now read the number inside
        $startPos = $this->pos;
        $numStr   = '';

        while ($this->pos < $this->length && $this->isDigit($this->input[$this->pos])) {
            $numStr .= $this->input[$this->pos];
            $this->pos++;
        }

        if ($numStr !== '') {
            $this->tokens[] = new Token(TokenType::ALTERNATE_CHAPTER, $numStr, $startPos);
        }

        // Consume closing parenthesis
        if ($this->pos < $this->length && $this->input[$this->pos] === ')') {
            $this->tokens[] = new Token(TokenType::CLOSE_PARENTHESIS, ')', $this->pos);
            $this->pos++;
        }
    }

    private function lastMeaningfulToken(): ?Token
    {
        for ($i = count($this->tokens) - 1; $i >= 0; $i--) {
            return $this->tokens[$i];
        }
        return null;
    }

    private function isDigit(string $ch): bool
    {
        return $ch >= '0' && $ch <= '9';
    }

    private function isLetterStart(int $pos): bool
    {
        // Use \G anchor at byte offset to match a single Unicode letter,
        // which correctly handles multibyte UTF-8 characters.
        return (bool) preg_match('/\G\p{L}/u', $this->input, matches: $m, offset: $pos);
    }
}
