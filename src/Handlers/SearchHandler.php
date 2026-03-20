<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Pipeline\QuoteContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SearchHandler extends AbstractHandler
{
    private const ENDPOINT_VERSION = '3.0';

    /** @var list<string>|null */
    private static ?array $cachedValidVersions = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Nyholm\Psr7\Response(200, [], null, $request->getProtocolVersion(), 'OK');
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);
        $this->validateRequestContentType($request);

        $params      = $this->getRequestParams($request);
        $contentType = $this->resolveResponseContentType($request, $params);
        $response    = $this->initResponse($request, $contentType);

        [$keyword, $version, $exactmatch] = $this->extractSearchParams($params);

        $mysqli = Connection::getConnection();
        $version = $this->validateVersion($mysqli, $version);
        $versionIndex = $this->loadVersionIndex($mysqli, $version);

        $searchResult = $this->executeSearch($mysqli, $version, $keyword, $exactmatch);
        $results = $this->mapSearchResults($searchResult, $version, $versionIndex);

        $body = new \stdClass();
        $body->results = $results;
        $body->errors  = [];
        $body->info    = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        return $this->buildSearchResponse($response, $contentType, $body, $results);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, string, bool}
     */
    private function extractSearchParams(array $params): array
    {
        $keywordRaw    = $params['keyword'] ?? '';
        $keyword       = is_string($keywordRaw) ? $keywordRaw : '';
        $versionRaw    = $params['version'] ?? '';
        $version       = is_string($versionRaw) ? $versionRaw : '';
        $exactmatchRaw = $params['exactmatch'] ?? false;
        $exactmatch    = match (true) {
            is_bool($exactmatchRaw)   => $exactmatchRaw,
            is_string($exactmatchRaw) => filter_var($exactmatchRaw, FILTER_VALIDATE_BOOLEAN),
            default                   => false,
        };

        if ($keyword === '') {
            throw new ValidationException('The keyword parameter is required.');
        }
        if ($version === '') {
            throw new ValidationException('The version parameter is required.');
        }

        return [$keyword, $version, $exactmatch];
    }

    private function validateVersion(\mysqli $mysqli, string $version): string
    {
        if (self::$cachedValidVersions === null) {
            self::$cachedValidVersions = [];
            $result = $mysqli->query("SELECT sigla FROM versions_available");
            if (!$result instanceof \mysqli_result) {
                error_log('Failed to query versions_available: ' . $mysqli->error);
                throw new InternalServerErrorException('An internal database error occurred.');
            }
            while ($row = $result->fetch_assoc()) {
                self::$cachedValidVersions[] = (string) $row['sigla'];
            }
        }
        $version = strtoupper($version);
        if (!in_array($version, self::$cachedValidVersions)) {
            throw new ValidationException('Not a valid version: ' . $version);
        }
        return $version;
    }

    /**
     * @return array{abbreviations: list<string>, books: list<string>, book_num: list<string>}
     */
    private function loadVersionIndex(\mysqli $mysqli, string $version): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $version)) {
            throw new ValidationException('Invalid version identifier format: ' . $version);
        }
        $abbreviations = $books = $book_num = [];
        $idxResult = $mysqli->query('SELECT * FROM ' . $version . '_idx');
        if (!$idxResult instanceof \mysqli_result) {
            error_log('Failed to load index for version ' . $version . ': ' . $mysqli->error);
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        while ($row = $idxResult->fetch_assoc()) {
            $abbreviations[] = (string) ($row['abbrev'] ?? '');
            $books[]         = (string) ($row['fullname'] ?? '');
            $book_num[]      = (string) ($row['book'] ?? '');
        }
        if (empty($abbreviations)) {
            throw new InternalServerErrorException('No index data found for version: ' . $version);
        }
        return ['abbreviations' => $abbreviations, 'books' => $books, 'book_num' => $book_num];
    }

    private function executeSearch(\mysqli $mysqli, string $version, string $keyword, bool $exactmatch): \mysqli_result
    {
        if ($exactmatch) {
            $regexKeyword = preg_quote($keyword, '/');
            $escapedRegexKeyword = $mysqli->real_escape_string($regexKeyword);
            $searchResult = $mysqli->query(
                "SELECT * FROM {$version} WHERE text RLIKE '\\\\b{$escapedRegexKeyword}\\\\b' ORDER BY book, chapter, verse"
            );
        } else {
            $sanitizedKeyword = preg_replace('/[+\-><~*"()]+/', '', $keyword) ?? $keyword;
            if (mb_strlen($sanitizedKeyword) < 4) {
                throw new ValidationException('Search keyword must be at least 4 characters long (use exactmatch=true for shorter keywords).');
            }
            $escapedSanitized = $mysqli->real_escape_string($sanitizedKeyword);
            $searchResult = $mysqli->query(
                "SELECT * FROM {$version} WHERE MATCH(text) AGAINST ('{$escapedSanitized}*' IN BOOLEAN MODE) ORDER BY book, chapter, verse"
            );
        }

        if (!$searchResult instanceof \mysqli_result) {
            error_log('MySQL ERROR ' . $mysqli->errno . ': ' . $mysqli->error);
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        return $searchResult;
    }

    /**
     * @param array{abbreviations: list<string>, books: list<string>, book_num: list<string>} $versionIndex
     * @return array<int, array<string, mixed>>
     */
    private function mapSearchResults(\mysqli_result $searchResult, string $version, array $versionIndex): array
    {
        $results = [];
        while ($row = $searchResult->fetch_assoc()) {
            $row['version']    = $version;
            $row['testament']  = (int) $row['testament'];
            $universal_booknum = $row['book'];
            $bookidx           = array_search($row['book'], $versionIndex['book_num']);
            if ($bookidx === false) {
                $bookidx = 0;
            }
            $row['bookabbrev']  = $versionIndex['abbreviations'][$bookidx] ?? '';
            $row['booknum']     = (int) $bookidx;
            $row['univbooknum'] = $universal_booknum;
            $row['book']        = $versionIndex['books'][$bookidx] ?? '';
            $row['section']     = (int) $row['section'];
            $row['chapter']     = (int) $row['chapter'];
            $row['verse']       = (int) $row['verse'];
            unset($row['verseID']);
            $results[] = $row;
        }
        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function buildSearchResponse(ResponseInterface $response, string $contentType, \stdClass $body, array $results): ResponseInterface
    {
        if ($contentType === 'application/json') {
            return $this->jsonResponse($response, $body);
        }

        if ($contentType === 'application/xml') {
            $root = '<?xml version="1.0" encoding="UTF-8"?><BibleGetSearch/>';
            $xml  = new \SimpleXMLElement($root);
            $xml->addChild('errors');
            $xmlInfo    = $xml->addChild('info');
            $xmlResults = $xml->addChild('results');
            $xmlInfo->addAttribute('ENDPOINT_VERSION', self::ENDPOINT_VERSION);

            foreach ($results as $row) {
                $resultNode = $xmlResults->addChild('result');
                foreach ($row as $key => $value) {
                    $resultNode[$key] = is_scalar($value) ? (string) $value : '';
                }
            }

            $xmlString = $xml->asXML();
            return $this->xmlResponse($response, $xmlString !== false ? $xmlString : '');
        }

        // HTML
        $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $this->htmlResponse($response, '<pre>' . htmlspecialchars($json !== false ? $json : '{}') . '</pre>');
    }
}
