<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

use BibleGet\Api\Pipeline\Transform\PsalmNumbering;
use BibleGet\Api\Services\CopyrightPolicy;
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

    /** @var int|null Year of the request log to write; null is the current year (tests inject one). */
    private ?int $logYear;

    public function __construct(QuoteContext $ctx, ?int $logYear = null)
    {
        $this->logYear         = $logYear;
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
     * Nothing else creates a new year's table, so when the insert finds it
     * missing (SQLSTATE 42P01) it creates it with create_yearly_log_table()
     * and retries once.
     *
     * Best-effort: a failed insert is logged and never fails the request.
     */
    private function logQuery(): void
    {
        $year = (string) ( $this->logYear ?? (int) date('Y') );
        try {
            try {
                $this->insertLogRow($year);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '42P01') {
                    throw $e;
                }
                $this->ctx->pdo->prepare('SELECT create_yearly_log_table(?)')->execute([$year]);
                $this->insertLogRow($year);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Request log insert into requests_log__' . $year . ' failed: ' . $e->getMessage());
        }
    }

    private function insertLogRow(string $year): void
    {
        $credential = $this->ctx->credential;
        $stmt       = $this->ctx->pdo->prepare(
            'INSERT INTO requests_log__' . $year
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
        // A psalm row's Hebrew chapter:verse, to align editions numbered differently.
        $row += PsalmNumbering::hebrewField($universal_booknum, $row['chapter'], $row['verse'], $currentVariant);
        // sort_order is kept here so SearchUtils::assignCanonicalOrder (called by
        // QuoteHandler after the pipeline runs) can rank rows; it is unset
        // by the helper before the row leaves the API boundary.
        unset($row['embedding'], $row['embedding_labse'], $row['text_hash'], $row['embedded_text_hash']);
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

    /**
     * A copyrighted edition's query asks for one row past the 30-verse cap (SqlCompiler): if that row came, the
     * passage was cut, so keep the first 30 and add a VERSES_CAPPED notice, which a client can show instead of
     * guessing why the passage stops.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function capIfCut(array $rows): array
    {
        $version = $this->ctx->formulatedVariants[$this->i] ?? '';
        if (count($rows) <= CopyrightPolicy::VERSE_CAP || !in_array($version, $this->ctx->copyrightLimitedVersions(), true)) {
            return $rows;
        }
        $rows                 = array_slice($rows, 0, CopyrightPolicy::VERSE_CAP);
        $first                = $rows[0];
        $last                 = $rows[CopyrightPolicy::VERSE_CAP - 1];
        $book                 = is_string($first['book'] ?? null) ? $first['book'] : '';
        $from                 = StringUtils::asInt($first['chapter'] ?? 0) . ':' . StringUtils::asInt($first['verse'] ?? 0);
        $to                   = StringUtils::asInt($last['chapter'] ?? 0) === StringUtils::asInt($first['chapter'] ?? 0)
            ? (string) StringUtils::asInt($last['verse'] ?? 0)
            : StringUtils::asInt($last['chapter'] ?? 0) . ':' . StringUtils::asInt($last['verse'] ?? 0);
        $this->ctx->notices[] = [
            'code'       => 'VERSES_CAPPED',
            'version'    => $version,
            'book'       => StringUtils::asInt($first['univbooknum'] ?? 0),
            'chapter'    => StringUtils::asInt($first['chapter'] ?? 0),
            'verse_from' => StringUtils::asInt($first['verse'] ?? 0),
            'verse_to'   => StringUtils::asInt($last['verse'] ?? 0),
            'reference'  => $this->currentOriginalQuery(),
            'limit'      => CopyrightPolicy::VERSE_CAP,
            'message'    => $book . ' ' . $from . '-' . $to . ': this edition is under copyright, so only the first '
                . CopyrightPolicy::VERSE_CAP . ' verses of this passage are given.',
        ];
        return $rows;
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
                        $rows[] = $prepared;
                    }
                }
                $rows = $this->capIfCut($rows);
                array_push($this->ctx->results, ...$rows);
                $this->noticeIfAllOmitted($rows);
            } else {
                $this->ctx->addErrorMessage(9, $this->currentOriginalQuery());
            }

            $this->i++;
        }
    }
}
