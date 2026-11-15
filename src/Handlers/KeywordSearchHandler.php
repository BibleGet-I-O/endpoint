<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Services\KeywordSearchService;
use BibleGet\Api\Util\SearchUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class KeywordSearchHandler extends AbstractHandler
{
    private const ENDPOINT_VERSION = '3.0';

    private const VALID_MATCH_MODES = ['fulltext', 'exact', 'boolean'];

    /**
     * Reset cached data (for testing only).
     */
    public static function resetCache(): void
    {
        KeywordSearchService::resetCache();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Nyholm\Psr7\Response(200, [], null, $request->getProtocolVersion(), 'OK');
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);
        $this->validateRequestContentType($request);
        $logger = LoggerFactory::create('api');

        $params      = $this->getRequestParams($request);
        $contentType = $this->resolveResponseContentType($request, $params);
        $response    = $this->initResponse($request, $contentType);

        [$keyword, $versions, $matchMode] = $this->extractSearchParams($params);

        $pdo     = Connection::getConnection();
        $service = new KeywordSearchService($pdo, $logger);

        // Validate every requested version up front, and require a single
        // shared ts_language (semantic / similar search have no such constraint).
        [$validatedVersions, $tsLanguage] = $service->validateVersions($versions, '/search/keyword');

        $allResults = [];
        foreach ($validatedVersions as $v) {
            $versionIndex = SearchUtils::loadVersionIndex($pdo, $v, $logger);
            $searchResult = $service->executeSearch($v, $keyword, $matchMode, $tsLanguage);
            array_push($allResults, ...$service->mapSearchResults($searchResult, $v, $versionIndex));
        }

        SearchUtils::assignCanonicalOrder($allResults);

        $body          = new \stdClass();
        $body->results = $allResults;
        $body->errors  = [];
        $body->info    = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        $response = $this->buildSearchResponse($response, $contentType, $body, $allResults);
        return $this->withCacheHeaders($request, $response);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, list<string>, string}
     */
    private function extractSearchParams(array $params): array
    {
        $keywordRaw = $params['keyword'] ?? '';
        $keyword    = is_string($keywordRaw) ? $keywordRaw : '';

        // Resolve match mode: explicit `match` param takes precedence over legacy `exactmatch`
        $matchRaw = $params['match'] ?? '';
        $match    = is_string($matchRaw) ? strtolower($matchRaw) : '';

        if ($match === '') {
            // Legacy backward compatibility: exactmatch=true → match=exact
            $exactmatchRaw = $params['exactmatch'] ?? false;
            $exactmatch    = match (true) {
                is_bool($exactmatchRaw)   => $exactmatchRaw,
                is_string($exactmatchRaw) => filter_var($exactmatchRaw, FILTER_VALIDATE_BOOLEAN),
                default                   => false,
            };
            $match = $exactmatch ? 'exact' : 'fulltext';
        }

        if ($keyword === '') {
            throw new ValidationException('The keyword parameter is required.');
        }
        if (!in_array($match, self::VALID_MATCH_MODES, true)) {
            throw new ValidationException(
                'Invalid match mode: ' . $match . '. Valid modes are: ' . implode(', ', self::VALID_MATCH_MODES)
            );
        }

        // Comma-separated `version=A,B,C` (single value still accepted).
        $versions = SearchUtils::parseVersionsParam($params['version'] ?? '');

        return [$keyword, $versions, $match];
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
