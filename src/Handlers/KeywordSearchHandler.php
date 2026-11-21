<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Util\AppVersion;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Services\CopyrightPolicy;
use BibleGet\Api\Services\KeywordSearchService;
use BibleGet\Api\Util\SearchUtils;
use BibleGet\Api\Util\StringUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class KeywordSearchHandler extends AbstractHandler
{
    private const VALID_MATCH_MODES  = ['fulltext', 'exact', 'boolean'];
    private const DEFAULT_PAGE_LIMIT = 50;
    private const MAX_PAGE_LIMIT     = 100;

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

        $credential = $request->getAttribute('credential');
        $credential = $credential instanceof Credential ? $credential : null;
        $policy     = CopyrightPolicy::fromDatabase($pdo);
        $page       = $this->extractPageParams($params, $validatedVersions);
        $info       = ['ENDPOINT_VERSION' => AppVersion::current()];

        if ($page === null) {
            $allResults = [];
            foreach ($validatedVersions as $v) {
                $versionIndex = SearchUtils::loadVersionIndex($pdo, $v, $logger);
                $searchResult = $service->executeSearch($v, $keyword, $matchMode, $tsLanguage);
                array_push($allResults, ...$service->mapSearchResults($searchResult, $v, $versionIndex));
            }
            $capped     = SearchUtils::applyCopyrightCap($allResults, $policy, $credential);
            $allResults = $capped['rows'];
            $info       = SearchUtils::withCapped($info, $capped['capped']);
        } else {
            [$allResults, $info] = $this->searchPage($service, $policy, $credential, $validatedVersions[0], $keyword, $matchMode, $tsLanguage, $page, $info, $logger);
        }
        SearchUtils::assignCanonicalOrder($allResults);
        if ($page !== null) {
            // A page's rows count from the start of the matches, not of the page.
            foreach ($allResults as &$row) {
                $row['canonical_order'] = StringUtils::asInt($row['canonical_order'] ?? 0) + $page['offset'];
            }
            unset($row);
        }

        $body          = new \stdClass();
        $body->results = $allResults;
        $body->errors  = [];
        $body->info    = $info;

        $response = $this->buildSearchResponse($response, $contentType, $body, $allResults);
        return $this->withCacheHeaders($request, $response);
    }

    /**
     * One page of one edition's matches in canonical order, the cap applied: a capped edition shows only its first
     * VERSE_CAP matches, so the window is clipped to them. `info.TOTAL` is what this caller may see.
     *
     * @param array{limit: int, offset: int} $page
     * @param array<string, mixed>           $info
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function searchPage(
        KeywordSearchService $service,
        CopyrightPolicy $policy,
        ?Credential $credential,
        string $version,
        string $keyword,
        string $matchMode,
        string $tsLanguage,
        array $page,
        array $info,
        LoggerInterface $logger
    ): array {
        $count   = $service->countMatches($version, $keyword, $matchMode, $tsLanguage);
        $capped  = $policy->isCapped($version, $credential);
        $visible = $capped ? min($count, CopyrightPolicy::VERSE_CAP) : $count;
        $limit   = min($page['limit'], max(0, $visible - $page['offset']));
        $rows    = [];
        if ($limit > 0) {
            $versionIndex = SearchUtils::loadVersionIndex(Connection::getConnection(), $version, $logger);
            $stmt         = $service->executeWindow($version, $keyword, $matchMode, $tsLanguage, $limit, $page['offset']);
            $rows         = $service->mapSearchResults($stmt, $version, $versionIndex);
        }
        $info          = SearchUtils::withCapped($info, $capped && $count > CopyrightPolicy::VERSE_CAP ? [$version => $count] : []);
        $info['TOTAL'] = $visible;
        return [$rows, $info];
    }

    /**
     * `limit` (1–100) and `offset` (≥ 0) for one edition, or null when there is no `limit` (every match, as before).
     *
     * @param array<string, mixed> $params
     * @param list<string>         $versions
     * @return array{limit: int, offset: int}|null
     * @throws ValidationException
     */
    private function extractPageParams(array $params, array $versions): ?array
    {
        if (!isset($params['limit']) || $params['limit'] === '') {
            return null;
        }
        if (count($versions) > 1) {
            throw new ValidationException('limit and offset page a single version; ask for one version at a time.');
        }
        $offsetRaw = $params['offset'] ?? 0;
        if (!is_numeric($offsetRaw) || (int) $offsetRaw < 0 || (string) (int) $offsetRaw !== trim((string) $offsetRaw)) {
            throw new ValidationException('offset must be a non-negative integer.');
        }
        return [
            'limit'  => SearchUtils::parseLimitParam($params['limit'], self::DEFAULT_PAGE_LIMIT, self::MAX_PAGE_LIMIT),
            'offset' => (int) $offsetRaw,
        ];
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
            $xmlInfo->addAttribute('ENDPOINT_VERSION', AppVersion::current());
            SearchUtils::addCappedXml($xmlInfo, $body->info);

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
