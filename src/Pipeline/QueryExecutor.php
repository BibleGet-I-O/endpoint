<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\TooManyRequestsException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Util\StringUtils;
use Psr\Log\LoggerInterface;

/**
 * Executes SQL queries and collects results.
 * Ported from the legacy QUERY_EXECUTOR class.
 */
class QueryExecutor
{
    private QuoteContext $ctx;
    private LoggerInterface $logger;
    private int $i                      = 0;
    private string $appid               = '';
    private string $domain              = '';
    private string $pluginversion       = '';
    private string $ipaddress           = '';
    private string $forwardedip         = '';
    private string $remote_address      = '';
    private string $realip              = '';
    private string $clientip            = '';
    private string $xquery              = '';
    private string $curYEAR             = '';
    private string $prevYEAR            = '';
    private string $geoip_json          = '';
    private bool $haveIPAddressOnRecord = false;
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
        $this->appid           = $ctx->DATA['appid'] != '' ? $ctx->DATA['appid'] : 'unknown';
        $this->domain          = $ctx->DATA['domain'] != '' ? $ctx->DATA['domain'] : 'unknown';
        $this->pluginversion   = $ctx->DATA['pluginversion'] != '' ? $ctx->DATA['pluginversion'] : 'unknown';
        $this->curYEAR         = date('Y');
        $this->prevYEAR        = (string) ( (int) $this->curYEAR - 1 );
    }

    /**
     * Get the request log table names to query for the 48-hour window.
     * Near the year boundary (Jan 1-2), includes the previous year's table.
     *
     * @return list<string>
     */
    private function getLogTables(): array
    {
        $tables = ['requests_log__' . $this->curYEAR];
        // Within the first 2 days of the year, also check previous year's table
        if ((int) date('z') < 2) {
            $prevTable = 'requests_log__' . $this->prevYEAR;
            // Verify the table exists before including it
            $stmt = $this->ctx->pdo->prepare(
                "SELECT EXISTS(SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?)"
            );
            $stmt->execute([$prevTable]);
            if ($stmt->fetchColumn()) {
                $tables[] = $prevTable;
            }
        }
        return $tables;
    }

    private static function validateIPAddress(string $ipaddress): string|false
    {
        return filter_var($ipaddress, FILTER_VALIDATE_IP);
    }

    private function getAndValidateIpAddress(): void
    {
        $forwardedRaw         = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $this->forwardedip    = is_string($forwardedRaw) ? $forwardedRaw : '';
        $remoteRaw            = $_SERVER['REMOTE_ADDR'] ?? '';
        $this->remote_address = is_string($remoteRaw) ? $remoteRaw : '';
        $realipRaw            = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        $this->realip         = is_string($realipRaw) ? $realipRaw : '';
        $clientipRaw          = $_SERVER['HTTP_CLIENT_IP'] ?? '';
        $this->clientip       = is_string($clientipRaw) ? $clientipRaw : '';

        // Use REMOTE_ADDR as the authoritative IP for rate limiting.
        // Only trust forwarded headers when REMOTE_ADDR is a known trusted proxy.
        $this->ipaddress = $this->remote_address;
        if ($this->ipaddress == '' && $this->forwardedip != '') {
            $this->ipaddress = trim(explode(',', $this->forwardedip)[0]);
        }
        if ($this->ipaddress == '' && $this->realip != '') {
            $this->ipaddress = $this->realip;
        }

        if (self::validateIPAddress($this->ipaddress) === false) {
            throw new ValidationException(
                'The BibleGet API endpoint cannot be used behind a proxy that hides the IP address from which the request is coming.'
            );
        }
    }

    private function isWhitelisted(string $domainOrIP): int|string|false
    {
        return array_search($domainOrIP, $this->ctx->WhitelistedDomainsIPs);
    }

    /**
     * Build a UNION ALL subquery across all relevant log tables for the 48-hour window.
     * Near the year boundary (Jan 1-2), includes the previous year's table.
     */
    private function buildLogUnion(string $whereClause): string
    {
        $tables = $this->getLogTables();
        $parts  = [];
        foreach ($tables as $table) {
            $parts[] = 'SELECT * FROM ' . $table;
        }
        return 'SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') AS combined_log WHERE ' . $whereClause;
    }

    /**
     * Build a UNION ALL subquery with aggregation across log tables.
     */
    private function buildLogUnionAggregated(string $selectExpr, string $whereClause, string $groupBy): string
    {
        $tables = $this->getLogTables();
        $parts  = [];
        foreach ($tables as $table) {
            $parts[] = 'SELECT * FROM ' . $table;
        }
        return 'SELECT ' . $selectExpr . ' FROM (' . implode(' UNION ALL ', $parts) . ') AS combined_log WHERE ' . $whereClause . ' ' . $groupBy;
    }

    private function checkIPAddressPastTwoDaysWithSameRequest(): void
    {
        if ($this->ipaddress === '') {
            return;
        }
        $sql  = $this->buildLogUnion('"WHO_IP" = ?::inet AND "QUERY" = ? AND "WHO_WHEN" > NOW() - INTERVAL \'2 days\'');
        $stmt = $this->ctx->pdo->prepare($sql);
        if ($stmt === false) {
            $this->logger->error('Rate-limit prepare failed');
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        $stmt->execute([$this->ipaddress, $this->xquery]);
        $rows = $stmt->fetchAll();

        $count = count($rows);
        if ($count > 10 && $count < 30) {
            $this->ctx->addErrorMessage(10, $this->currentOriginalQuery());
            $firstRow                    = $rows[0];
            $this->geoip_json            = is_array($firstRow) ? StringUtils::asString($firstRow['WHO_WHERE_JSON'] ?? '') : '';
            $this->haveIPAddressOnRecord = true;
        } elseif ($count > 29) {
            throw new TooManyRequestsException(
                QuoteContext::$errorMessages[11] . ' > ' . $this->currentOriginalQuery(),
                172800 // 2 days in seconds
            );
        }
    }

    private function checkQueriesFromSameIPAddress(): void
    {
        if ($this->ipaddress === '') {
            return;
        }
        $sql  = $this->buildLogUnionAggregated(
            'COUNT(*) AS cnt',
            '"WHO_IP" = ?::inet AND "WHO_WHEN" > NOW() - INTERVAL \'2 days\'',
            ''
        );
        $stmt = $this->ctx->pdo->prepare($sql);
        if ($stmt === false) {
            $this->logger->error('Rate-limit prepare failed');
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        $stmt->execute([$this->ipaddress]);
        $count = (int) $stmt->fetchColumn();

        if ($count > 100) {
            throw new TooManyRequestsException(
                QuoteContext::$errorMessages[12] . ' > ' . $this->currentOriginalQuery(),
                172800
            );
        }
    }

    private function checkRequestsFromSameOrigin(): void
    {
        $sql  = $this->buildLogUnionAggregated(
            '"ORIGIN", COUNT(*) AS "ORIGIN_CNT"',
            '"ORIGIN" != \'\' AND "ORIGIN" = ? AND "QUERY" = ? AND "WHO_WHEN" > NOW() - INTERVAL \'2 days\'',
            'GROUP BY "ORIGIN"'
        );
        $stmt = $this->ctx->pdo->prepare($sql);
        if ($stmt === false) {
            $this->logger->error('Rate-limit prepare failed');
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        $stmt->execute([$this->ctx->originHeader, $this->xquery]);
        $originRow = $stmt->fetch();
        if (is_array($originRow) && array_key_exists('ORIGIN_CNT', $originRow)) {
            if ($originRow['ORIGIN_CNT'] > 10 && $originRow['ORIGIN_CNT'] < 30) {
                $this->ctx->addErrorMessage(10, $this->currentOriginalQuery());
            } elseif ($originRow['ORIGIN_CNT'] > 29) {
                throw new TooManyRequestsException(
                    QuoteContext::$errorMessages[11] . ' > ' . $this->currentOriginalQuery(),
                    172800
                );
            }
        }
    }

    private function checkDiverseRequestsFromSameOrigin(): void
    {
        $sql  = $this->buildLogUnionAggregated(
            '"ORIGIN", COUNT(*) AS "ORIGIN_CNT"',
            '"ORIGIN" != \'\' AND "ORIGIN" = ? AND "WHO_WHEN" > NOW() - INTERVAL \'2 days\'',
            'GROUP BY "ORIGIN"'
        );
        $stmt = $this->ctx->pdo->prepare($sql);
        if ($stmt === false) {
            $this->logger->error('Rate-limit prepare failed');
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        $stmt->execute([$this->ctx->originHeader]);
        $originRow = $stmt->fetch();
        if (is_array($originRow) && array_key_exists('ORIGIN_CNT', $originRow) && $originRow['ORIGIN_CNT'] > 100) {
            throw new TooManyRequestsException(
                QuoteContext::$errorMessages[12] . ' > ' . $this->currentOriginalQuery(),
                172800
            );
        }
    }

    /**
     * Enforce rate limits and log the query atomically under PostgreSQL
     * advisory locks keyed on both the client IP and the request Origin.
     *
     * Two locks are acquired using the two-argument form of
     * pg_advisory_xact_lock(namespace, key) — namespace 1 for IP and
     * namespace 2 for Origin — so that concurrent requests from the same
     * Origin on different IPs are also serialised, not only same-IP requests.
     * Using distinct namespaces prevents key-space collisions between IP and
     * Origin hashes.  Locks are always acquired in namespace order (1 then 2)
     * to prevent deadlocks.
     *
     * The locks are held until the log INSERT commits, closing the TOCTOU
     * window where concurrent requests could observe stale counts before the
     * current request is recorded.
     *
     * Returns true to signal the caller that logQuery() has already run
     * inside the transaction and must not be called again.
     */
    private function enforceQueryLimitsAndLog(): bool
    {
        // crc32() on 64-bit PHP returns uint32 range (0–4294967295).
        // pg_advisory_xact_lock takes int4 (−2147483648–2147483647), so
        // reinterpret values above 0x7FFFFFFF as their signed int32 equivalent.
        $toInt32   = static fn (int $v): int => $v > 0x7FFFFFFF ? $v - 0x100000000 : $v;
        $ipKey     = $this->ipaddress !== '' ? $toInt32(crc32($this->ipaddress)) : 0;
        $originKey = $this->ctx->originHeader !== '' ? $toInt32(crc32($this->ctx->originHeader)) : 0;

        $this->ctx->pdo->beginTransaction();
        try {
            // Namespace 1 = IP, namespace 2 = Origin.  Always acquired in
            // namespace order to prevent deadlocks.
            $lockStmt = $this->ctx->pdo->prepare('SELECT pg_advisory_xact_lock(:ns, :key)');
            $lockStmt->execute(['ns' => 1, 'key' => $ipKey]);
            $lockStmt->execute(['ns' => 2, 'key' => $originKey]);
            $this->checkIPAddressPastTwoDaysWithSameRequest();
            $this->checkQueriesFromSameIPAddress();
            $this->checkRequestsFromSameOrigin();
            $this->checkDiverseRequestsFromSameOrigin();
            $this->logQuery();
            $this->ctx->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->ctx->pdo->inTransaction()) {
                $this->ctx->pdo->rollBack();
            }
            throw $e;
        }

        return true;
    }

    private function getGeoIPInfoFromLogsElseOnline(): void
    {
        $geoIPFromLogs = $this->getGeoIPFromLogs();
        if ($geoIPFromLogs instanceof \PDOStatement) {
            $iprow = $geoIPFromLogs->fetch(\PDO::FETCH_ASSOC);
            if (is_array($iprow)) {
                $this->geoip_json            = StringUtils::asString($iprow['WHO_WHERE_JSON'] ?? '');
                $this->haveIPAddressOnRecord = true;
            } elseif ($this->ipaddress != '') {
                // Geo-IP lookup is best-effort and only used for logging.
                // Defer the external API call to avoid adding up to 5s latency
                // to the synchronous response path. Log a placeholder instead.
                $this->geoip_json = '{"PENDING":"geo-ip lookup deferred"}';
            }
        } elseif ($this->ipaddress != '') {
            $this->geoip_json = '{"PENDING":"geo-ip lookup deferred"}';
        }
    }

    private function getGeoIPFromLogs(): \PDOStatement|false
    {
        if ($this->ipaddress != '') {
            $sql  = $this->buildLogUnion('"WHO_IP" = ?::inet AND "WHO_WHERE_JSON" NOT LIKE \'{"ERROR":"%"}\'');
            $stmt = $this->ctx->pdo->prepare($sql);
            if ($stmt === false) {
                $this->logger->error('Geo-IP log lookup prepare failed');
                return false;
            }
            $stmt->execute([$this->ipaddress]);
            return $stmt;
        }
        return false;
    }

    private function geoIPInfoIsEmptyOrIsError(): bool
    {
        $pregmatch = preg_quote('{"ERROR":"', '/');
        return $this->haveIPAddressOnRecord === false || $this->geoip_json === '' || (bool) preg_match('/' . $pregmatch . '/', $this->geoip_json);
    }

    /**
     * Insert a row into the current year's request log.
     *
     * This method does NOT catch exceptions.  When called inside the
     * advisory-locked transaction in enforceQueryLimitsAndLog() any failure
     * must propagate so the transaction is rolled back and the request is not
     * counted.  Callers that want best-effort logging must catch exceptions
     * themselves.
     */
    private function logQuery(): void
    {
        $sql  = 'INSERT INTO requests_log__' . $this->curYEAR
            . ' ( "WHO_IP","WHO_WHERE_JSON","HEADERS_JSON","ORIGIN","QUERY","ORIGINALQUERY","REQUEST_METHOD","HTTP_CLIENT_IP","HTTP_X_FORWARDED_FOR","HTTP_X_REAL_IP","REMOTE_ADDR","APP_ID","DOMAIN","PLUGINVERSION" )'
            . ' VALUES ( ?::inet, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )';
        $stmt = $this->ctx->pdo->prepare($sql);
        if ($stmt === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        $originalQuery = $this->ctx->originalQueries[$this->i] ?? '';
        $stmt->execute([
            $this->ipaddress,
            $this->geoip_json,
            $this->ctx->jsonEncodedRequestHeaders,
            $this->ctx->originHeader,
            $this->xquery,
            $originalQuery,
            $this->ctx->requestMethod,
            $this->clientip,
            $this->forwardedip,
            $this->realip,
            $this->remote_address,
            $this->appid,
            $this->domain,
            $this->pluginversion,
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
        // verseID is kept here so SearchUtils::assignCanonicalOrder (called by
        // QuoteHandler after the pipeline runs) can rank rows; it is unset
        // by the helper before the row leaves the API boundary.
        unset($row['embedding'], $row['text_hash'], $row['embedded_text_hash']);
        $row['chapter']       = is_numeric($row['chapter']) ? (int) $row['chapter'] : 0;
        $row['originalquery'] = $this->ctx->originalQueries[$this->i] ?? '';

        return $row;
    }

    private function currentOriginalQuery(): string
    {
        return $this->ctx->originalQueries[$this->i] ?? '';
    }

    public function executeSQLQueries(): void
    {
        $this->getAndValidateIpAddress();

        // Use server-derived host for domain whitelist check to prevent spoofing via client-supplied domain
        $serverHost     = is_string($_SERVER['SERVER_NAME'] ?? null) ? $_SERVER['SERVER_NAME'] : '';
        $notWhitelisted = ( $this->isWhitelisted($serverHost) === false && $this->isWhitelisted($this->ipaddress) === false );

        foreach ($this->sqlqueries as $xquery) {
            $this->xquery = $xquery;

            $result = $this->ctx->pdo->query($xquery);

            if ($result instanceof \PDOStatement) {
                $this->ctx->incrementGoodQueryCount();

                if ($this->geoIPInfoIsEmptyOrIsError()) {
                    $this->getGeoIPInfoFromLogsElseOnline();
                }

                $this->ipaddress = $this->ipaddress != '' ? $this->ipaddress : '0.0.0.0';

                if ($this->geoip_json === '') {
                    $this->geoip_json = '{"ERROR":""}';
                }

                if ($notWhitelisted) {
                    $this->enforceQueryLimitsAndLog();
                } else {
                    // Whitelisted requests bypass rate-limit checks; logging is best-effort.
                    try {
                        $this->logQuery();
                    } catch (\Throwable $e) {
                        $this->logger->error('Request log insert failed: ' . $e->getMessage());
                    }
                }

                while (is_array($fetchedRow = $result->fetch(\PDO::FETCH_ASSOC))) {
                    $prepared = $this->prepareResponse(StringUtils::toAssocArray($fetchedRow));
                    if ($prepared !== null) {
                        $this->ctx->results[] = $prepared;
                    }
                }
            } else {
                $this->ctx->addErrorMessage(9, $this->currentOriginalQuery());
            }

            $this->i++;
        }
    }
}
