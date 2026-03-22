<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Util\StringUtils;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Shared data context for the quote pipeline (Validator → Formulator → Executor).
 * Replaces the public properties of the old BIBLEGET_QUOTE class.
 */
class QuoteContext
{
    public const ENDPOINT_VERSION = '3.0';

    public const ALLOWED_PREFER_ORIGINS = ['GREEK', 'HEBREW'];

    /** @var array<int|string, string> */
    public static array $errorMessages = [
        0  => 'The first query must start with a valid book indicator.',
        1  => 'You must have a valid chapter following a book indicator.',
        2  => 'The book indicator is not valid. Please check the documentation for a list of correct book indicators.',
        3  => 'You cannot request discontinuous verses without first indicating a chapter for the discontinuous verses.',
        4  => 'A request for discontinuous verses must contain two valid verse numbers on either side of a discontinuous verse indicator.',
        5  => 'A chapter-verse separator must be preceded by a valid chapter number and followed by a valid verse number.',
        6  => 'A request for a range of verses must contain two valid verse numbers on either side of a verse range indicator.',
        7  => 'If there is a chapter-verse construct following a dash, there must also be a chapter-verse construct preceding the same dash.',
        8  => 'Multiple verse ranges have been requested, but there are not enough verse separators. Multiple verse ranges assume there are verse separators that connect them.',
        9  => 'Notation Error. Please check your citation notation.',
        10 => 'Please use a caching mechanism, you seem to be submitting numerous requests for the same query.',
        11 => 'You are submitting too many requests with the same query. You must use a caching mechanism. Once you have implemented a caching mechanism you may have to wait a couple of days before getting service again. Otherwise contact the service management to request service again.',
        12 => 'You are submitting a very large amount of requests to the endpoint. Please slow down. If you believe there has been an error you may contact the service management.',
    ];

    /** @var array<string, string> */
    public static array $defaultParameters = [
        'query'          => '',
        'return'         => '',
        'version'        => '',
        'domain'         => '',
        'appid'          => '',
        'pluginversion'  => '',
        'forceversion'   => '',
        'forcecopyright' => '',
        'preferorigin'   => '',
    ];

    public \mysqli $mysqli;
    public LoggerInterface $logger;
    public string $detectedNotation = 'ENGLISH';
    /** @var array<string> */
    public array $WhitelistedDomainsIPs      = [];
    public string $jsonEncodedRequestHeaders = '';
    public string $originHeader              = '';
    public string $requestMethod             = '';

    /** @var array<int, string> */
    public array $queries = [];
    /** @var array<int, string> */
    public array $validatedQueries = [];
    /** @var array<int, array<int, string>> */
    public array $validatedVariants = [];
    /** @var array<int, string> */
    public array $formulatedQueries = [];
    /** @var array<int, string> */
    public array $formulatedVariants = [];
    /** @var array<int, string> */
    public array $originalQueries = [];
    /** @var array<int, string> */
    public array $VALID_VERSIONS = [];
    /** @var array<string, string> */
    public array $VALID_VERSIONS_FULLNAME = [];
    /** @var array<int, string> */
    public array $COPYRIGHT_VERSIONS = [];
    /** @var array<int, string> */
    public array $PROTESTANT_VERSIONS = [];
    /** @var array<int, string> */
    public array $CATHOLIC_VERSIONS = [];
    /** @var array<int, string> */
    public array $REQUESTED_VERSIONS = [];
    /** @var array<int, string> */
    public array $REQUESTED_COPYRIGHTED_VERSIONS = [];
    /** @var array<int, array<int, array<int, string>>> */
    public array $BIBLEBOOKS = [];
    /** @var array<string, array{abbreviations: array<int, string>, biblebooks: array<int, string>, chapter_limit: array<int, int>, verse_limit: array<int, array<int, int>>, book_num: array<int, int>}> */
    public array $INDEXES = [];
    /** @var array<string, string> */
    public array $DATA = [];

    /** @var array<int, \BibleGet\Api\Pipeline\Ast\BibleQuery> */
    public array $parsedQueries = [];

    /** @var array<array{errNum: int, errMessage: string}> */
    public array $errors = [];
    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    /**
     * @param array<string, string> $params
     */
    public function __construct(array $params, ?LoggerInterface $logger = null, string $originHeader = '', string $requestMethod = 'GET', string $requestHeadersJson = '')
    {
        $this->DATA                      = array_merge(self::$defaultParameters, $params);
        $this->DATA['preferorigin']      = in_array($this->DATA['preferorigin'], self::ALLOWED_PREFER_ORIGINS) ? $this->DATA['preferorigin'] : '';
        $this->logger                    = $logger ?? new NullLogger();
        $this->originHeader              = $originHeader;
        $this->requestMethod             = $requestMethod;
        $this->jsonEncodedRequestHeaders = $requestHeadersJson;
    }

    /**
     * Initialize database connection and load metadata.
     */
    public function initialize(): void
    {
        $this->mysqli                = Connection::getConnection();
        $this->WhitelistedDomainsIPs = Connection::getWhitelistedDomainsIPs();
        $this->populateVersionsInfo();
        $this->prepareBibleBooks();
        $this->prepareRequestedVersions();
        $this->prepareIndexes();
    }

    public function addErrorMessage(int|string $num, string $str = ''): void
    {
        $errMessage = '';
        if (is_string($num)) {
            $errMessage = $num;
            $num        = 13;
        } else {
            $errMessage = self::$errorMessages[$num] ?? '';
        }

        $this->errors[] = [
            'errNum'     => $num,
            'errMessage' => $errMessage . ( $str !== '' ? ' > ' . $str : '' ),
        ];
    }

    public function incrementBadQueryCount(): void
    {
        if ($this->mysqli->query('UPDATE counter SET bad = bad + 1') === false) {
            $this->logger->error('Failed to increment bad query counter: ' . $this->mysqli->error);
        }
    }

    public function incrementGoodQueryCount(): void
    {
        if ($this->mysqli->query('UPDATE counter SET good = good + 1') === false) {
            $this->logger->error('Failed to increment good query counter: ' . $this->mysqli->error);
        }
    }

    public static function stringWithUpperAndLowerCaseVariants(string $str): bool
    {
        return StringUtils::stringWithUpperAndLowerCaseVariants($str);
    }

    public static function toProperCase(string $txt): string
    {
        return StringUtils::toProperCase($txt);
    }

    /**
     * @param array<int, array<int, array<int, string>>> $haystack
     */
    public static function idxOf(string $needle, array $haystack): int|false
    {
        foreach ($haystack as $index => $value) {
            if (is_array($value)) {
                foreach ($value as $subValue) {
                    if (is_array($subValue) && in_array($needle, $subValue)) {
                        return $index;
                    }
                }
            }
        }
        return false;
    }

    // ── Query string normalization ────────────────────────────────────

    public function queryStrClean(): void
    {
        $querystr               = self::removeWhitespace($this->DATA['query']);
        $querystr               = trim($querystr);
        $querystr               = self::convertAllDashesToHyphens($querystr);
        $this->detectedNotation = self::detectAndNormalizeNotation($querystr);

        $queries       = explode(';', $querystr);
        $queries       = self::removeEmptyItems($queries);
        $queries       = array_map([self::class, 'toProperCase'], $queries);
        $this->queries = $queries;
    }

    private static function normalizeBibleBook(string $str): string
    {
        return StringUtils::normalizeBibleBook($str);
    }

    private static function detectAndNormalizeNotation(string &$querystr): string
    {
        $detectedNotation = '';
        $find             = ['.', ',', ':'];
        $replace          = ['', '.', ','];

        // Check if '.' appears after the first chapter/verse separator (: or ,),
        // not before it (where it would be an abbreviation dot like "Jn.3:16")
        $hasDotAfterSeparator = false;
        if (strpos($querystr, '.') !== false) {
            $queries = explode(';', $querystr);
            foreach ($queries as $q) {
                if (preg_match('/[,:]/', $q, $m, PREG_OFFSET_CAPTURE)) {
                    $separatorPos   = $m[0][1];
                    $afterSeparator = substr($q, $separatorPos + 1);
                    if (strpos($afterSeparator, '.') !== false) {
                        $hasDotAfterSeparator = true;
                        break;
                    }
                }
            }
        }

        if (strpos($querystr, ':') !== false && $hasDotAfterSeparator) {
            $detectedNotation = 'MIXED';
        } elseif (strpos($querystr, ':') !== false && strpos($querystr, ',') !== false && strpos($querystr, ';') !== false) {
            // Detect per-query separator by finding the first ':' or ',' after the chapter number
            $queries    = explode(';', $querystr);
            $separators = [];
            foreach ($queries as $q) {
                // Find the first separator character (: or ,) after stripping book indicator
                if (preg_match('/[:;,]/', $q, $sepMatch)) {
                    $separators[] = $sepMatch[0];
                }
            }
            if (in_array(':', $separators) && in_array(',', $separators)) {
                $detectedNotation = 'MIXED';
            } elseif (in_array(':', $separators)) {
                $detectedNotation = 'ENGLISH';
                $querystr         = str_replace($find, $replace, $querystr);
            } else {
                $detectedNotation = 'EUROPEAN';
            }
        } elseif (strpos($querystr, ':') !== false) {
            $detectedNotation = 'ENGLISH';
            $querystr         = str_replace($find, $replace, $querystr);
        } else {
            $detectedNotation = 'EUROPEAN';
        }

        return $detectedNotation;
    }

    private static function removeWhitespace(string $querystr): string
    {
        $querystr = preg_replace('/\s+/', '', $querystr) ?? $querystr;
        return str_replace(' ', '', $querystr);
    }

    private static function convertAllDashesToHyphens(string $querystr): string
    {
        return preg_replace('/[\x{2011}-\x{2015}|\x{2212}|\x{23AF}]/u', '-', $querystr) ?? $querystr;
    }

    /**
     * @param array<int, string> $queries
     * @return array<int, string>
     */
    private static function removeEmptyItems(array $queries): array
    {
        return array_values(array_filter($queries, function ($var) {
            return $var !== '';
        }));
    }

    // ── Metadata loading ────────────────────────────────────

    private function isValidVersion(string $version): bool
    {
        return in_array($version, $this->VALID_VERSIONS);
    }

    private function populateVersionsInfo(): void
    {
        $result = $this->mysqli->query("SELECT * FROM versions_available WHERE type = 'BIBLE'");
        if (!$result instanceof \mysqli_result) {
            $this->logger->error('MySQL ERROR ' . $this->mysqli->errno . ': ' . $this->mysqli->error);
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        while ($row = mysqli_fetch_assoc($result)) {
            $output_info_array                                     = [
                $row['fullname'],
                $row['year'],
                $row['language'],
                $row['imprimatur'],
                $row['canon'],
                $row['copyright_holder'],
                $row['notes'],
            ];
            $this->VALID_VERSIONS[]                                = (string) $row['sigla'];
            $this->VALID_VERSIONS_FULLNAME[(string) $row['sigla']] = implode('|', $output_info_array);
            if ((int) $row['copyright'] === 1) {
                $this->COPYRIGHT_VERSIONS[] = (string) $row['sigla'];
            }
            if ($row['canon'] === 'CATHOLIC') {
                $this->CATHOLIC_VERSIONS[] = (string) $row['sigla'];
            } elseif ($row['canon'] === 'PROTESTANT') {
                $this->PROTESTANT_VERSIONS[] = (string) $row['sigla'];
            }
        }
    }

    private function prepareIndexes(): void
    {
        $indexes = [];
        foreach ($this->REQUESTED_VERSIONS as $variant) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $variant)) {
                throw new ValidationException('Invalid version identifier format: ' . $variant);
            }
            $abbreviations = $bbbooks = $chapter_limit = $verse_limit = $book_num = [];
            $result        = $this->mysqli->query('SELECT * FROM ' . $variant . '_idx ORDER BY book');
            if ($result === false) {
                $this->logger->error('Failed to load index for version ' . $variant . ': ' . $this->mysqli->error);
                throw new InternalServerErrorException('An internal database error occurred.');
            }
            if ($result instanceof \mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $abbreviations[] = (string) $row['abbrev'];
                    $bbbooks[]       = (string) $row['fullname'];
                    $chapter_limit[] = (int) $row['chapters'];
                    $verse_limit[]   = array_map('intval', explode(',', (string) $row['verses_last']));
                    $book_num[]      = (int) $row['book'];
                }
            }
            $indexes[$variant]['abbreviations'] = $abbreviations;
            $indexes[$variant]['biblebooks']    = $bbbooks;
            $indexes[$variant]['chapter_limit'] = $chapter_limit;
            $indexes[$variant]['verse_limit']   = $verse_limit;
            $indexes[$variant]['book_num']      = $book_num;
        }
        $this->INDEXES = $indexes;
    }

    private function prepareBibleBooks(): void
    {
        $result1 = $this->mysqli->query('SELECT * FROM biblebooks_fullname ORDER BY BOOK');
        if (!$result1 instanceof \mysqli_result) {
            $this->logger->error('MySQL ERROR ' . $this->mysqli->errno . ': ' . $this->mysqli->error);
            throw new InternalServerErrorException('An internal database error occurred.');
        }

        $cols  = mysqli_num_fields($result1);
        $names = [];
        $finfo = mysqli_fetch_fields($result1);
        foreach ($finfo as $val) {
            $names[] = $val->name;
        }

        $result2 = $this->mysqli->query('SELECT * FROM biblebooks_abbr ORDER BY BOOK');
        if (!$result2 instanceof \mysqli_result) {
            $this->logger->error('MySQL ERROR ' . $this->mysqli->errno . ': ' . $this->mysqli->error);
            throw new InternalServerErrorException('An internal database error occurred.');
        }

        $n = 0;
        while ($row1 = mysqli_fetch_assoc($result1)) {
            $row2 = mysqli_fetch_assoc($result2);
            if ($row2 === null || $row2 === false) {
                throw new InternalServerErrorException('biblebooks_abbr has fewer rows than biblebooks_fullname.');
            }
            if (( $row1['BOOK'] ?? null ) !== ( $row2['BOOK'] ?? null )) {
                throw new InternalServerErrorException('biblebooks_fullname and biblebooks_abbr BOOK keys are mismatched.');
            }
            $this->BIBLEBOOKS[$n] = [];
            for ($x = 1; $x < $cols; $x++) {
                $val1                     = (string) ( $row1[$names[$x]] ?? '' );
                $val2                     = (string) ( $row2[$names[$x]] ?? '' );
                $temparray                = [$val1, $val2];
                $arr1                     = explode(' | ', $val1);
                $booknames                = array_map([self::class, 'normalizeBibleBook'], $arr1);
                $arr2                     = explode(' | ', $val2);
                $abbrevs                  = ( count($arr2) > 1 ) ? array_map([self::class, 'normalizeBibleBook'], $arr2) : [];
                $this->BIBLEBOOKS[$n][$x] = array_merge($temparray, $booknames, $abbrevs);
            }
            $n++;
        }
    }

    private function prepareRequestedVersions(): void
    {
        $temp = isset($this->DATA['version']) && $this->DATA['version'] !== ''
            ? array_map('trim', explode(',', strtoupper($this->DATA['version'])))
            : ['CEI2008'];

        foreach ($temp as $version) {
            if (isset($this->DATA['forceversion']) && $this->DATA['forceversion'] === 'true') {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $version)) {
                    $this->addErrorMessage('Invalid version identifier format: <' . $version . '>');
                    continue;
                }
                $idxCheck = $this->mysqli->query('SELECT 1 FROM ' . $version . '_idx LIMIT 1');
                if (!$idxCheck instanceof \mysqli_result) {
                    $this->addErrorMessage('No index table found for forced version: <' . $version . '>');
                    continue;
                }
                $this->REQUESTED_VERSIONS[] = $version;
            } else {
                if ($this->isValidVersion($version)) {
                    $this->REQUESTED_VERSIONS[] = $version;
                } else {
                    $this->addErrorMessage('Not a valid version: <' . $version . '>, valid versions are <' . implode(' | ', $this->VALID_VERSIONS) . '>');
                    continue;
                }
            }
            if (isset($this->DATA['forcecopyright']) && $this->DATA['forcecopyright'] === 'true') {
                $this->REQUESTED_COPYRIGHTED_VERSIONS[] = $version;
            }
        }

        if (count($this->REQUESTED_VERSIONS) < 1) {
            throw new ValidationException('No valid Bible versions were requested.');
        }
    }
}
