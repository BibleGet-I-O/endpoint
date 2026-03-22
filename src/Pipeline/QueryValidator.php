<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

use BibleGet\Api\Pipeline\Ast\BibleQuery;
use BibleGet\Api\Pipeline\Parser\ParseException;
use BibleGet\Api\Pipeline\Parser\ReferenceParser;
use BibleGet\Api\Pipeline\Tokenizer\ReferenceTokenizer;
use BibleGet\Api\Pipeline\Tokenizer\TokenType;
use BibleGet\Api\Pipeline\Validator\AstValidator;

/**
 * Validates Bible reference syntax and structure.
 *
 * Delegates to Tokenizer → Parser → AstValidator internally.
 * Public API (validateQueries) and its effects on QuoteContext remain unchanged.
 */
class QueryValidator
{
    private QuoteContext $ctx;
    private ReferenceTokenizer $tokenizer;
    private ReferenceParser $parser;

    /** @var int Previous book index (1-based) for book inheritance across queries */
    private int $previousBook = 0;

    /** @var string Previous book name for error messages */
    private string $previousBookName = '';

    public function __construct(QuoteContext $ctx)
    {
        $this->ctx       = $ctx;
        $this->tokenizer = new ReferenceTokenizer();
        $this->parser    = new ReferenceParser();
    }

    public function validateQueries(): bool
    {
        if (empty($this->ctx->queries)) {
            return false;
        }

        // First query must start with a valid book indicator
        if (!$this->startsWithBookIndicator($this->ctx->queries[0])) {
            $this->ctx->addErrorMessage(0);
            $this->ctx->incrementBadQueryCount();
            return false;
        }

        foreach ($this->ctx->queries as $query) {
            $this->validateSingleQuery($query);
        }

        return true;
    }

    private function validateSingleQuery(string $query): void
    {
        // Tokenize
        $tokens = $this->tokenizer->tokenize($query);

        // Check that a chapter number follows the book
        if (!$this->hasChapterAfterBook($tokens)) {
            $this->ctx->addErrorMessage(1);
            $this->ctx->incrementBadQueryCount();
            return;
        }

        // Parse
        try {
            $ast = $this->parser->parse($tokens);
        } catch (ParseException) {
            $this->ctx->addErrorMessage(9);
            $this->ctx->incrementBadQueryCount();
            return;
        }

        // Determine the book name for this query
        $bookName = $this->extractBookName($tokens);

        // Handle book inheritance: if no book in this query, use previous
        if ($ast->book === 0 && $bookName === null) {
            if ($this->previousBook === 0) {
                $this->ctx->addErrorMessage(0);
                $this->ctx->incrementBadQueryCount();
                return;
            }
            // Rebuild AST with inherited book
            $ast      = new BibleQuery($this->previousBook, $ast->segments);
            $bookName = $this->previousBookName;
        }

        // Validate against DB metadata
        $validator = new AstValidator(
            $this->ctx->BIBLEBOOKS,
            $this->ctx->INDEXES,
            $this->ctx->REQUESTED_VERSIONS
        );

        if ($ast->book !== 0) {
            // Book already resolved (inherited) — just validate bounds
            $validated = $this->validatePreResolved($ast, $validator);
        } else {
            // Resolve book from name via AstValidator
            $validated = $validator->validate($ast, $tokens);
        }

        if ($validated === null) {
            foreach ($validator->getErrors() as $error) {
                $this->ctx->addErrorMessage($error->message);
                $this->ctx->incrementBadQueryCount();
            }
            return;
        }

        // Update book context for subsequent queries
        $this->previousBook     = $validated->book;
        $this->previousBookName = $bookName ?? $this->previousBookName;

        // Populate context outputs (same as old implementation)
        $this->ctx->validatedQueries[]  = $query;
        $this->ctx->validatedVariants[] = $validator->getValidatedVariants();
        $this->ctx->parsedQueries[]     = $validated;
    }

    /**
     * Validate a BibleQuery that already has its book resolved (inherited from previous query).
     */
    private function validatePreResolved(BibleQuery $ast, AstValidator $validator): ?BibleQuery
    {
        // Create a temporary token stream with just the book for the validator
        $dummyTokens = [
            new \BibleGet\Api\Pipeline\Tokenizer\Token(
                TokenType::BOOK_NAME,
                $this->previousBookName,
                0
            ),
            new \BibleGet\Api\Pipeline\Tokenizer\Token(
                TokenType::EOF,
                '',
                0
            ),
        ];
        // Use the validator but pass our pre-resolved book
        $result = $validator->validate(new BibleQuery(0, $ast->segments), $dummyTokens);
        if ($result === null) {
            return null;
        }
        // The validator resolved the book from the dummy tokens, but we want our inherited book
        return new BibleQuery($ast->book, $result->segments);
    }

    private function startsWithBookIndicator(string $query): bool
    {
        return (bool) ( preg_match('/^[1-4]{0,1}\p{Lu}\p{Ll}*/u', $query)
            || preg_match('/^[1-4]{0,1}(\p{L}\p{M}*)+/u', $query) );
    }

    /**
     * Check that tokenized output has a CHAPTER_NUMBER after the book indicator.
     *
     * @param \BibleGet\Api\Pipeline\Tokenizer\Token[] $tokens
     */
    private function hasChapterAfterBook(array $tokens): bool
    {
        $foundBook = false;
        foreach ($tokens as $token) {
            if ($token->type === TokenType::BOOK_NAME) {
                $foundBook = true;
                continue;
            }
            if ($foundBook && $token->type === TokenType::CHAPTER_NUMBER) {
                return true;
            }
            // If no book was found, the first CHAPTER_NUMBER is the "chapter" (book inherited)
            if (!$foundBook && $token->type === TokenType::CHAPTER_NUMBER) {
                return true;
            }
            if ($token->type === TokenType::EOF) {
                break;
            }
        }
        // If there's a book name but no chapter, check if the query is book-only
        // (the old validator considered this invalid — rule 1)
        return !$foundBook;
    }

    /**
     * Extract the full book name (with numeric prefix) from tokens.
     *
     * @param \BibleGet\Api\Pipeline\Tokenizer\Token[] $tokens
     */
    private function extractBookName(array $tokens): ?string
    {
        $prefix = '';
        $name   = '';
        foreach ($tokens as $token) {
            if ($token->type === TokenType::BOOK_NUMERIC_PREFIX) {
                $prefix = $token->value;
            } elseif ($token->type === TokenType::BOOK_NAME) {
                $name = $token->value;
                break;
            }
        }
        return $name !== '' ? $prefix . $name : null;
    }
}
