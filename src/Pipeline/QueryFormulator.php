<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

use BibleGet\Api\Pipeline\Compiler\SqlCompiler;
use BibleGet\Api\Pipeline\Transform\EstherRemapper;
use BibleGet\Api\Pipeline\Transform\PsalmNumbering;

/**
 * Translates validated Bible references into SQL queries.
 *
 * Delegates to EstherRemapper → PsalmNumbering → SqlCompiler internally using the parsed ASTs
 * from QuoteContext. Public API (formulateSQLQueries) and its effects on
 * QuoteContext remain unchanged.
 */
class QueryFormulator
{
    private QuoteContext $ctx;
    private SqlCompiler $compiler;
    private EstherRemapper $estherRemapper;
    private PsalmNumbering $psalmNumbering;

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
        $this->psalmNumbering = new PsalmNumbering();
    }

    public function formulateSQLQueries(): void
    {
        $nn = 0;

        foreach ($this->ctx->REQUESTED_VERSIONS as $version) {
            foreach ($this->ctx->parsedQueries as $i => $parsedQuery) {
                // Check if this version was validated for this query
                if (!in_array($version, $this->ctx->validatedVariants[$i] ?? [], true)) {
                    continue;
                }

                // Determine base textTradition for this book/version
                $baseTextTradition = $this->buildTextTradition($parsedQuery->book, $version);

                // Apply Esther Greek additions remapping (AST → AST); with numbering=edition,
                // Esther is read as the edition prints it (VGCL, DRB: chapters 11-16 as stored).
                [$remappedQuery, $perSegmentOrigins] = $this->ctx->editionNumbering()
                    ? $this->estherRemapper->editionRemap(
                        $parsedQuery,
                        $version,
                        $baseTextTradition,
                        $this->ctx->DATA['text_tradition'] !== ''
                    )
                    : $this->estherRemapper->remap($parsedQuery, $version, $baseTextTradition);

                // Resolve Psalm numbers (Hebrew by default) to this version's chapter and verse;
                // with numbering=edition they already are this version's own, and of two
                // numbers the one this version prints is taken.
                $remappedQuery = $this->ctx->editionNumbering()
                    ? $this->psalmNumbering->editionChapters($remappedQuery, $version)
                    : $this->psalmNumbering->resolve($remappedQuery, $version);

                // Compile AST → SQL with per-segment textTradition
                $sqls = $this->compiler->compile(
                    $remappedQuery,
                    $version,
                    $perSegmentOrigins,
                    $this->ctx->copyrightLimitedVersions(),
                    $this->ctx->editionNumbering()
                        ? $this->estherRemapper->editionOrder($parsedQuery->book, $version)
                        : SqlCompiler::STORAGE_ORDER
                );

                $originalQuery = $this->ctx->validatedQueries[$i];
                $segments      = $this->compiler->segmentRefs(
                    $remappedQuery,
                    $this->ctx->validatedBookNames[$i] ?? '',
                    $this->ctx->detectedNotation
                );

                foreach ($sqls as $k => $sql) {
                    $this->sqlQueries[$nn]              = $sql;
                    $this->queriesVersions[$nn]         = $version;
                    $this->originalQueries[$nn]         = $originalQuery;
                    $this->ctx->formulatedSegments[$nn] = $segments[$k] ?? null;
                    $nn++;
                }
            }
        }

        $this->ctx->formulatedQueries  = $this->sqlQueries;
        $this->ctx->originalQueries    = $this->originalQueries;
        $this->ctx->formulatedVariants = $this->queriesVersions;
    }

    private const ESTHER_BOOK_NUM = 19;

    private function buildTextTradition(int $book, string $version): string
    {
        if ($book === self::ESTHER_BOOK_NUM && in_array($version, $this->ctx->CATHOLIC_VERSIONS, true)) {
            $textTradition = $this->ctx->DATA['text_tradition'] ?? '';
            $origin        = in_array($textTradition, QuoteContext::ALLOWED_TEXT_TRADITIONS, true)
                ? $textTradition
                : 'GREEK';
            return " AND text_tradition = '" . $origin . "'";
        }
        return '';
    }
}
