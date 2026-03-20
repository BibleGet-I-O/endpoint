<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

use BibleGet\Api\Http\Exception\TooManyRequestsException;
use BibleGet\Api\Http\Exception\ValidationException;

/**
 * Executes SQL queries and collects results.
 * Ported from the legacy QUERY_EXECUTOR class.
 */
class QueryExecutor
{
    private QuoteContext $ctx;
    private int $i                     = 0;
    private string $appid              = '';
    private string $domain             = '';
    private string $pluginversion      = '';
    private string $ipaddress          = '';
    private string $forwardedip        = '';
    private string $remote_address     = '';
    private string $realip             = '';
    private string $clientip           = '';
    private string $xquery             = '';
    private string $curYEAR            = '';
    private string $geoip_json         = '';
    private bool $haveIPAddressOnRecord = false;
    /** @var array<int, string> */
    private array $sqlqueries          = [];
    /** @var array<int, string> */
    private array $queriesversions     = [];

    public function __construct(QuoteContext $ctx)
    {
        $this->ctx              = $ctx;
        $this->sqlqueries       = $ctx->formulatedQueries;
        $this->queriesversions  = $ctx->formulatedVariants;
        $this->appid            = $ctx->DATA['appid'] != '' ? $ctx->DATA['appid'] : 'unknown';
        $this->domain           = $ctx->DATA['domain'] != '' ? $ctx->DATA['domain'] : 'unknown';
        $this->pluginversion    = $ctx->DATA['pluginversion'] != '' ? $ctx->DATA['pluginversion'] : 'unknown';
        $this->curYEAR          = date('Y');
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

    private function checkIPAddressPastTwoDaysWithSameRequest(): void
    {
        if ($this->ipaddress === '') {
            return;
        }
        $stmt = $this->ctx->mysqli->prepare("SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON(?) AND QUERY = ? AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY)");
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ss', $this->ipaddress, $this->xquery);
        $stmt->execute();
        $ipresult = $stmt->get_result();

        if ($ipresult instanceof \mysqli_result) {
            if ($ipresult->num_rows > 10 && $ipresult->num_rows < 30) {
                $this->ctx->addErrorMessage(10, $this->xquery);
                $iprow = $ipresult->fetch_assoc();
                $this->geoip_json = (string) ($iprow['WHO_WHERE_JSON'] ?? '');
                $this->haveIPAddressOnRecord = true;
            } elseif ($ipresult->num_rows > 29) {
                throw new TooManyRequestsException(
                    QuoteContext::$errorMessages[11] . ' > ' . $this->xquery,
                    172800 // 2 days in seconds
                );
            }
        }
    }

    private function checkQueriesFromSameIPAddress(): void
    {
        if ($this->ipaddress === '') {
            return;
        }
        $stmt = $this->ctx->mysqli->prepare("SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON(?) AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY)");
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('s', $this->ipaddress);
        $stmt->execute();
        $ipresult = $stmt->get_result();

        if ($ipresult instanceof \mysqli_result && $ipresult->num_rows > 100) {
            throw new TooManyRequestsException(
                QuoteContext::$errorMessages[12] . ' > ' . $this->xquery,
                172800
            );
        }
    }

    private function checkRequestsFromSameOrigin(): void
    {
        $stmt = $this->ctx->mysqli->prepare("SELECT ORIGIN, COUNT(*) AS ORIGIN_CNT FROM requests_log__" . $this->curYEAR . " WHERE ORIGIN != '' AND ORIGIN = ? AND QUERY = ? AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY) GROUP BY ORIGIN");
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ss', $this->ctx->originHeader, $this->xquery);
        $stmt->execute();
        $originres = $stmt->get_result();
        if ($originres instanceof \mysqli_result && $originres->num_rows > 0) {
            $originRow = $originres->fetch_assoc();
            if (is_array($originRow) && array_key_exists('ORIGIN_CNT', $originRow)) {
                if ($originRow['ORIGIN_CNT'] > 10 && $originRow['ORIGIN_CNT'] < 30) {
                    $this->ctx->addErrorMessage(10, $this->xquery);
                } elseif ($originRow['ORIGIN_CNT'] > 29) {
                    throw new TooManyRequestsException(
                        QuoteContext::$errorMessages[11] . ' > ' . $this->xquery,
                        172800
                    );
                }
            }
        }
    }

    private function checkDiverseRequestsFromSameOrigin(): void
    {
        $stmt = $this->ctx->mysqli->prepare("SELECT ORIGIN, COUNT(*) AS ORIGIN_CNT FROM requests_log__" . $this->curYEAR . " WHERE ORIGIN != '' AND ORIGIN = ? AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY) GROUP BY ORIGIN");
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('s', $this->ctx->originHeader);
        $stmt->execute();
        $originres = $stmt->get_result();
        if ($originres instanceof \mysqli_result && $originres->num_rows > 0) {
            $originRow = $originres->fetch_assoc();
            if (is_array($originRow) && array_key_exists('ORIGIN_CNT', $originRow) && $originRow['ORIGIN_CNT'] > 100) {
                throw new TooManyRequestsException(
                    QuoteContext::$errorMessages[12] . ' > ' . $this->xquery,
                    172800
                );
            }
        }
    }

    private function enforceQueryLimits(): void
    {
        $this->checkIPAddressPastTwoDaysWithSameRequest();
        $this->checkQueriesFromSameIPAddress();
        $this->checkRequestsFromSameOrigin();
        $this->checkDiverseRequestsFromSameOrigin();
    }

    private function getGeoIPInfoFromLogsElseOnline(): void
    {
        $geoIPFromLogs = $this->getGeoIPFromLogs();
        if ($geoIPFromLogs instanceof \mysqli_result && $geoIPFromLogs->num_rows > 0) {
            $iprow = $geoIPFromLogs->fetch_assoc();
            $this->geoip_json = (string) ($iprow['WHO_WHERE_JSON'] ?? '');
            $this->haveIPAddressOnRecord = true;
        } elseif ($this->ipaddress != '') {
            // Geo-IP lookup is best-effort and only used for logging.
            // Defer the external API call to avoid adding up to 5s latency
            // to the synchronous response path. Log a placeholder instead.
            $this->geoip_json = '{"PENDING":"geo-ip lookup deferred"}';
        }
    }

    private function getGeoIPFromLogs(): \mysqli_result|bool
    {
        if ($this->ipaddress != '') {
            $stmt = $this->ctx->mysqli->prepare("SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON(?) AND WHO_WHERE_JSON NOT LIKE '{\"ERROR\":\"%\"}'");
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('s', $this->ipaddress);
            $stmt->execute();
            return $stmt->get_result();
        }
        return false;
    }

    private function geoIPInfoIsEmptyOrIsError(): bool
    {
        $pregmatch = preg_quote('{"ERROR":"', '/');
        return $this->haveIPAddressOnRecord === false || $this->geoip_json === '' || (bool) preg_match('/' . $pregmatch . '/', $this->geoip_json);
    }

    private function logQuery(): void
    {
        $stmt = $this->ctx->mysqli->prepare("INSERT INTO requests_log__" . $this->curYEAR . " ( WHO_IP,WHO_WHERE_JSON,HEADERS_JSON,ORIGIN,QUERY,ORIGINALQUERY,REQUEST_METHOD,HTTP_CLIENT_IP,HTTP_X_FORWARDED_FOR,HTTP_X_REAL_IP,REMOTE_ADDR,APP_ID,DOMAIN,PLUGINVERSION ) VALUES ( INET6_ATON( ? ), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )");
        if ($stmt === false) {
            return;
        }
        $originalQuery = $this->ctx->originalQueries[$this->i] ?? '';
        $stmt->bind_param('ssssssssssssss', $this->ipaddress, $this->geoip_json, $this->ctx->jsonEncodedRequestHeaders, $this->ctx->originHeader, $this->xquery, $originalQuery, $this->ctx->requestMethod, $this->clientip, $this->forwardedip, $this->realip, $this->remote_address, $this->appid, $this->domain, $this->pluginversion);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function prepareResponse(array $row): array
    {
        $currentVariant = $this->queriesversions[$this->i];

        $row['version']     = strtoupper($currentVariant);
        $row['testament']   = is_numeric($row['testament']) ? (int) $row['testament'] : 0;

        $universal_booknum  = $row['book'];
        $booknum            = array_search($row['book'], $this->ctx->INDEXES[$currentVariant]['book_num']);
        if ($booknum === false) {
            $booknum = 0;
        }
        $row['bookabbrev']  = $this->ctx->INDEXES[$currentVariant]['abbreviations'][$booknum] ?? '';
        $row['booknum']     = $booknum;
        $row['univbooknum'] = $universal_booknum;
        $row['book']        = $this->ctx->INDEXES[$currentVariant]['biblebooks'][$booknum];

        $row['section']     = is_numeric($row['section']) ? (int) $row['section'] : 0;
        unset($row['verseID']);
        $row['chapter']     = is_numeric($row['chapter']) ? (int) $row['chapter'] : 0;
        $row['originalquery'] = $this->ctx->originalQueries[$this->i] ?? '';

        return $row;
    }

    public function executeSQLQueries(): void
    {
        $this->getAndValidateIpAddress();

        // Use server-derived host for domain whitelist check to prevent spoofing via client-supplied domain
        $serverHost = is_string($_SERVER['SERVER_NAME'] ?? null) ? $_SERVER['SERVER_NAME'] : '';
        $notWhitelisted = ($this->isWhitelisted($serverHost) === false && $this->isWhitelisted($this->ipaddress) === false);

        foreach ($this->sqlqueries as $xquery) {
            $this->xquery = $xquery;

            if ($notWhitelisted) {
                $this->enforceQueryLimits();
            }

            $result = $this->ctx->mysqli->query($xquery);

            if ($result instanceof \mysqli_result) {
                $this->ctx->incrementGoodQueryCount();

                if ($this->geoIPInfoIsEmptyOrIsError()) {
                    $this->getGeoIPInfoFromLogsElseOnline();
                }

                $this->ipaddress = $this->ipaddress != '' ? $this->ipaddress : '0.0.0.0';

                if ($this->geoip_json === '') {
                    $this->geoip_json = '{"ERROR":""}';
                }

                $this->logQuery();

                while ($row = $result->fetch_assoc()) {
                    $this->ctx->results[] = $this->prepareResponse($row);
                }
            } else {
                $this->ctx->addErrorMessage(9, $this->xquery);
            }

            $this->i++;
        }
    }
}
