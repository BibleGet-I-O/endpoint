<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

use BibleGet\Api\Pipeline\Compiler\SqlCompiler;
use BibleGet\Api\Pipeline\Transform\EstherRemapper;
use BibleGet\Api\Pipeline\Transform\PsalmChapterSwapper;

/**
 * Translates validated Bible references into SQL queries.
 *
 * Delegates to EstherRemapper → SqlCompiler internally using the parsed ASTs
 * from QuoteContext. Public API (formulateSQLQueries) and its effects on
 * QuoteContext remain unchanged.
 */
class QueryFormulator
{
    private QuoteContext $ctx;
    private SqlCompiler $compiler;
    private EstherRemapper $estherRemapper;
    private PsalmChapterSwapper $psalmSwapper;

    /** @var array<int, string> */
    public array $sqlQueries = [];
    /** @var array<int, string> */
    public array $queriesVersions = [];
    /** @var array<int, string> */
    public array $originalQueries = [];

    public function __construct(QuoteContext $ctx)
    {
        $this->ctx            = $ctx;
        $this->compiler       = new SqlCompiler();
        $this->estherRemapper = new EstherRemapper($ctx->CATHOLIC_VERSIONS);
        $this->psalmSwapper   = new PsalmChapterSwapper();
    }

    public function formulateSQLQueries(): void
    {
        $nn = 0;

        foreach ($this->ctx->REQUESTED_VERSIONS as $version) {
            foreach ($this->ctx->parsedQueries as $i => $parsedQuery) {
                // Check if this version was validated for this query
                if (!in_array($version, $this->ctx->validatedVariants[$i])) {
                    continue;
                }

                // Determine base preferOrigin for this book/version
                $basePreferOrigin = $this->buildPreferOrigin($parsedQuery->book, $version);

                // Apply Esther Greek additions remapping (AST → AST)
                [$remappedQuery, $perSegmentOrigins] = $this->estherRemapper->remap(
                    $parsedQuery,
                    $version,
                    $basePreferOrigin
                );

                // Apply Psalm chapter swap for Vulgate-numbered versions
                $remappedQuery = $this->psalmSwapper->swap($remappedQuery, $version);

                // Compile AST → SQL with per-segment preferOrigin
                $sqls = $this->compiler->compile(
                    $remappedQuery,
                    $version,
                    $perSegmentOrigins,
                    $this->ctx->COPYRIGHT_VERSIONS,
                    $this->ctx->REQUESTED_COPYRIGHTED_VERSIONS
                );

                $originalQuery = $this->ctx->validatedQueries[$i];

                foreach ($sqls as $sql) {
                    $this->sqlQueries[$nn]      = $sql;
                    $this->queriesVersions[$nn] = $version;
                    $this->originalQueries[$nn] = $originalQuery;
                    $nn++;
                }
            }
        }

        $this->ctx->formulatedQueries  = $this->sqlQueries;
        $this->ctx->originalQueries    = $this->originalQueries;
        $this->ctx->formulatedVariants = $this->queriesVersions;
    }

    private const ESTHER_BOOK_NUM = 19;

    private function buildPreferOrigin(int $book, string $version): string
    {
        if ($book === self::ESTHER_BOOK_NUM && in_array($version, $this->ctx->CATHOLIC_VERSIONS)) {
            $preferOrigin = $this->ctx->DATA['preferorigin'] ?? '';
            $origin       = in_array($preferOrigin, QuoteContext::ALLOWED_PREFER_ORIGINS, true)
                ? $preferOrigin
                : 'GREEK';
            return " AND verseorigin = '" . $origin . "'";
        }
        return '';
    }
}
