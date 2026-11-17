<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

use BibleGet\Api\Util\Placement;
use BibleGet\Api\Util\StringUtils;
use Psr\Log\LoggerInterface;

/**
 * Executes SQL queries and collects results.
 * Ported from the legacy QUERY_EXECUTOR class.
 *
 * Rate limiting is per credential (RateLimitMiddleware); the request log names
 * the credential, never an IP address or its geolocation.
 */
class QueryExecutor
{
    private QuoteContext $ctx;
    private LoggerInterface $logger;
    private int $i         = 0;
    private string $xquery = '';
    /** @var array<int, string> */
    private array $sqlqueries = [];
    /** @var array<int, string> */
    private array $queriesversions = [];

    public function __construct(QuoteContext $ctx)
    {
        $this->ctx             = $ctx;
        $this->logger          = $ctx->logger;
        $this->sqlqueries      = $ctx->formulatedQueries;
        $this->queriesversions = $ctx->formulatedVariants;
    }

    /**
     * Insert a row into the current year's request log: the credential (both
     * NULL for an uncredentialed request), origin, query and method. The
     * legacy IP, geolocation, header and app columns keep their defaults.
     *
     * Best-effort: a failed insert is logged and never fails the request.
     */
    private function logQuery(): void
    {
        $credential = $this->ctx->credential;
        try {
            $stmt = $this->ctx->pdo->prepare(
                'INSERT INTO requests_log__' . date('Y')
                . ' ( credential_type, credential_id, "ORIGIN", "QUERY", "ORIGINALQUERY", "REQUEST_METHOD" )'
                . ' VALUES ( ?, ?, ?, ?, ?, ? )'
            );
            $stmt->execute([
                $credential?->type->value,
                $credential?->id,
                $this->ctx->originHeader,
                $this->xquery,
                $this->currentOriginalQuery(),
                $this->ctx->requestMethod,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Request log insert failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function prepareResponse(array $row): ?array
    {
        $currentVariant = $this->queriesversions[$this->i];

        $row['version']   = strtoupper($currentVariant);
        $row['testament'] = is_numeric($row['testament']) ? (int) $row['testament'] : 0;

        $universal_booknum = $row['book'];
        $booknum           = array_search($row['book'], $this->ctx->INDEXES[$currentVariant]['book_num']);
        if ($booknum === false) {
            $this->logger->error('Unmapped book number ' . ( is_scalar($row['book']) ? (string) $row['book'] : 'unknown' ) . ' in version index for quote result');
            return null;
        }
        $row['bookabbrev']  = $this->ctx->INDEXES[$currentVariant]['abbreviations'][$booknum] ?? '';
        $row['booknum']     = $booknum;
        $row['univbooknum'] = $universal_booknum;
        $row['book']        = $this->ctx->INDEXES[$currentVariant]['biblebooks'][$booknum];

        $row['section'] = is_numeric($row['section']) ? (int) $row['section'] : 0;
        // sort_order is kept here so SearchUtils::assignCanonicalOrder (called by
        // QuoteHandler after the pipeline runs) can rank rows; it is unset
        // by the helper before the row leaves the API boundary.
        unset($row['embedding'], $row['text_hash'], $row['embedded_text_hash']);
        // How the edition prints the row (printed_chapter, verse_label, omitted).
        $row                  = array_merge($row, Placement::fromRow($row));
        $row['originalquery'] = $this->ctx->originalQueries[$this->i] ?? '';

        return $row;
    }

    /**
     * A segment that asked only for verses the edition omits (every row it
     * returned is omitted) gets a VERSE_OMITTED notice; a segment that also
     * returned other verses, or that has no SegmentRef (whole chapter), none.
     *
     * @param list<array<string, mixed>> $rows The rows this query returned.
     */
    private function noticeIfAllOmitted(array $rows): void
    {
        $segment = $this->ctx->formulatedSegments[$this->i] ?? null;
        if ($segment === null || $rows === [] || in_array(false, array_column($rows, 'omitted'), true)) {
            return;
        }
        $verses               = $segment->verseFrom . ( $segment->verseTo !== $segment->verseFrom ? '-' . $segment->verseTo : '' );
        $book                 = is_string($rows[0]['book'] ?? null) ? $rows[0]['book'] : '';
        $this->ctx->notices[] = [
            'code'       => 'VERSE_OMITTED',
            'version'    => $this->ctx->formulatedVariants[$this->i] ?? '',
            'book'       => $segment->book,
            'chapter'    => $segment->chapter,
            'verse_from' => $segment->verseFrom,
            'verse_to'   => $segment->verseTo,
            'reference'  => $segment->reference,
            'message'    => $book . ' ' . $segment->chapter . ':' . $verses
                . ' is not in the text of this edition: the critical text omits it; the number is printed without text.',
        ];
    }

    private function currentOriginalQuery(): string
    {
        return $this->ctx->originalQueries[$this->i] ?? '';
    }

    public function executeSQLQueries(): void
    {
        foreach ($this->sqlqueries as $xquery) {
            $this->xquery = $xquery;

            $result = $this->ctx->pdo->query($xquery);

            if ($result instanceof \PDOStatement) {
                $this->ctx->incrementGoodQueryCount();
                $this->logQuery();

                $rows = [];
                while (is_array($fetchedRow = $result->fetch(\PDO::FETCH_ASSOC))) {
                    $prepared = $this->prepareResponse(StringUtils::toAssocArray($fetchedRow));
                    if ($prepared !== null) {
                        $this->ctx->results[] = $prepared;
                        $rows[]               = $prepared;
                    }
                }
                $this->noticeIfAllOmitted($rows);
            } else {
                $this->ctx->addErrorMessage(9, $this->currentOriginalQuery());
            }

            $this->i++;
        }
    }
}
