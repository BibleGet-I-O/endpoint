<?php

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

        $keywordRaw    = $params['keyword'] ?? '';
        $keyword       = is_string($keywordRaw) ? $keywordRaw : '';
        $versionRaw    = $params['version'] ?? '';
        $version       = is_string($versionRaw) ? $versionRaw : '';
        $exactmatchRaw = $params['exactmatch'] ?? '';
        $exactmatch    = is_string($exactmatchRaw) ? $exactmatchRaw : '';

        if ($keyword === '') {
            throw new ValidationException('The keyword parameter is required.');
        }
        if ($version === '') {
            throw new ValidationException('The version parameter is required.');
        }

        $mysqli = Connection::getConnection();

        // Validate version
        $validVersions = [];
        $result = $mysqli->query("SELECT sigla FROM versions_available");
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $validVersions[] = $row['sigla'];
            }
        }
        $version = strtoupper($version);
        if (!in_array($version, $validVersions)) {
            throw new ValidationException('Not a valid version: ' . $version);
        }

        // Load indexes for this version
        $abbreviations = $bbbooks = $book_num = [];
        $idxResult = $mysqli->query('SELECT * FROM ' . $version . '_idx');
        if ($idxResult instanceof \mysqli_result) {
            while ($row = $idxResult->fetch_assoc()) {
                $abbreviations[] = $row['abbrev'];
                $bbbooks[]       = $row['fullname'];
                $book_num[]      = $row['book'];
            }
        }

        // Build search query
        $escapedKeyword = $mysqli->real_escape_string($keyword);
        $results = [];
        $errors  = [];

        if ($exactmatch === 'true') {
            // Exact match: RLIKE word boundary match, allows 3-letter words
            $regexKeyword = preg_quote($keyword, '/');
            $escapedRegexKeyword = $mysqli->real_escape_string($regexKeyword);
            $searchResult = $mysqli->query(
                "SELECT * FROM {$version} WHERE text RLIKE '[[:<:]]{$escapedRegexKeyword}[[:>:]]' ORDER BY book, chapter, verse"
            );
        } else {
            // Default: boolean fulltext search with wildcard
            // Strip MySQL boolean mode operators from user input
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
            throw new InternalServerErrorException('MySQL ERROR ' . $mysqli->errno . ': ' . $mysqli->error);
        }

        while ($row = $searchResult->fetch_assoc()) {
            $row['version']    = $version;
            $row['testament']  = (int) $row['testament'];
            $universal_booknum = $row['book'];
            $bookidx           = array_search($row['book'], $book_num);
            if ($bookidx === false) {
                $bookidx = 0;
            }
            $row['bookabbrev'] = $abbreviations[$bookidx] ?? '';
            $row['booknum']    = (int) $bookidx;
            $row['univbooknum'] = $universal_booknum;
            $row['book']       = $bbbooks[$bookidx] ?? '';
            $row['section']    = (int) $row['section'];
            $row['chapter']    = (int) $row['chapter'];
            $row['verse']      = (int) $row['verse'];
            unset($row['verseID']);
            $results[] = $row;
        }

        $body = new \stdClass();
        $body->results = $results;
        $body->errors  = $errors;
        $body->info    = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        if ($contentType === 'application/json') {
            return $this->jsonResponse($response, $body);
        }

        if ($contentType === 'application/xml') {
            $root = '<?xml version="1.0" encoding="UTF-8"?><BibleGetSearch/>';
            $xml  = new \SimpleXMLElement($root);
            $xmlErrors  = $xml->addChild('errors');
            $xmlInfo    = $xml->addChild('info');
            $xmlResults = $xml->addChild('results');
            $xmlInfo->addAttribute('ENDPOINT_VERSION', self::ENDPOINT_VERSION);

            foreach ($results as $row) {
                $resultNode = $xmlResults->addChild('result');
                foreach ($row as $key => $value) {
                    $resultNode[$key] = (string) $value;
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
