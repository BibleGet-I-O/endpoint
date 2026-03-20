<?php

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\QueryFormulator;
use BibleGet\Api\Pipeline\QueryExecutor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class QuoteHandler extends AbstractHandler
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = self::createEmptyResponse($request);
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);
        $this->validateRequestContentType($request);

        $params      = $this->getRequestParams($request);
        $contentType = $this->resolveResponseContentType($request, $params);
        $response    = $this->initResponse($request, $contentType);

        // Block bots
        $userAgent = $request->getHeaderLine('User-Agent');
        if ($userAgent !== '' && preg_match('/bot|crawl|slurp|spider/i', $userAgent)) {
            return $response->withStatus(403);
        }

        // Build context and run pipeline
        $stringParams = [];
        foreach ($params as $key => $value) {
            $stringParams[$key] = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
        }
        $ctx = new QuoteContext(
            $stringParams,
            $request->getHeaderLine('Origin'),
            $request->getMethod(),
            json_encode($request->getHeaders()) ?: '{}'
        );
        $ctx->initialize();

        $queryRaw = $params['query'] ?? '';
        $query = is_string($queryRaw) ? $queryRaw : '';
        if ($query === '') {
            throw new ValidationException('The query parameter is required.');
        }

        $ctx->queryStrClean();

        if ($ctx->detectedNotation === 'MIXED') {
            throw new ValidationException('Mixed notations have been detected, please use either english or european notation.');
        }

        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        if (!empty($ctx->validatedQueries)) {
            $formulator = new QueryFormulator($ctx);
            $formulator->formulateSQLQueries();

            $executor = new QueryExecutor($ctx);
            $executor->executeSQLQueries();
        }

        // Build response body
        return $this->buildResponse($response, $contentType, $ctx);
    }

    private function buildResponse(ResponseInterface $response, string $contentType, QuoteContext $ctx): ResponseInterface
    {
        $bibleVersionsInfo = [];
        foreach ($ctx->formulatedVariants as $variant) {
            if (isset($ctx->VALID_VERSIONS_FULLNAME[$variant])) {
                $bibleVersionsInfo[$variant] = $ctx->VALID_VERSIONS_FULLNAME[$variant];
            }
        }

        if ($contentType === 'application/json') {
            $body = new \stdClass();
            $body->results = $ctx->results;
            $body->errors  = $ctx->errors;
            $body->info    = [
                'ENDPOINT_VERSION'  => QuoteContext::ENDPOINT_VERSION,
                'detectedNotation'  => $ctx->detectedNotation,
                'bibleVersionsInfo' => $bibleVersionsInfo,
            ];
            return $this->jsonResponse($response, $body);
        }

        if ($contentType === 'application/xml') {
            $root = '<?xml version="1.0" encoding="UTF-8"?><BibleQuote/>';
            $xml  = new \SimpleXMLElement($root);
            $errors  = $xml->addChild('errors');
            $info    = $xml->addChild('info');
            $results = $xml->addChild('results');

            $info->addAttribute('ENDPOINT_VERSION', QuoteContext::ENDPOINT_VERSION);
            $info['detectedNotation']  = $ctx->detectedNotation;
            $encoded = json_encode($bibleVersionsInfo);
            $info['bibleVersionsInfo'] = $encoded !== false ? $encoded : '{}';

            foreach ($ctx->errors as $err) {
                $errNode = $errors->addChild('error', $err['errMessage']);
                $errNode->addAttribute('errNum', (string) $err['errNum']);
            }

            foreach ($ctx->results as $row) {
                $resultNode = $results->addChild('result');
                foreach ($row as $key => $value) {
                    $resultNode[$key] = is_scalar($value) ? (string) $value : '';
                }
            }

            $xmlString = $xml->asXML();
            return $this->xmlResponse($response, $xmlString !== false ? $xmlString : '');
        }

        // HTML
        $html = $this->buildHtmlResponse($ctx, $bibleVersionsInfo);
        return $this->htmlResponse($response, $html);
    }

    /**
     * @param array<string, string> $bibleVersionsInfo
     */
    private function buildHtmlResponse(QuoteContext $ctx, array $bibleVersionsInfo): string
    {
        $resultsHtml = '<div class="results bibleQuote">';
        $version = $book = $chapter = '';
        $paragraphOpen = false;

        foreach ($ctx->results as $row) {
            $rowVersion = isset($row['version']) && is_scalar($row['version']) ? (string) $row['version'] : '';
            $rowBook    = isset($row['book']) && is_scalar($row['book']) ? (string) $row['book'] : '';
            $rowChapter = isset($row['chapter']) && is_scalar($row['chapter']) ? (string) $row['chapter'] : '';
            $rowVerse   = isset($row['verse']) && is_scalar($row['verse']) ? (string) $row['verse'] : '';
            $rowText    = isset($row['text']) && is_scalar($row['text']) ? (string) $row['text'] : '';

            if ($rowVersion !== $version) {
                if ($paragraphOpen) {
                    $resultsHtml .= '</p>';
                    $paragraphOpen = false;
                }
                $version = $rowVersion;
                $resultsHtml .= '<p class="version bibleVersion">' . htmlspecialchars($version) . '</p>';
                $book = ''; $chapter = '';
            }
            if ($rowBook !== $book || $rowChapter !== $chapter) {
                if ($paragraphOpen) {
                    $resultsHtml .= '</p>';
                }
                $book    = $rowBook;
                $chapter = $rowChapter;
                $resultsHtml .= '<p class="book bookChapter">' . htmlspecialchars($book) . '&nbsp;' . htmlspecialchars($chapter) . '</p>';
                $resultsHtml .= '<p class="verses versesParagraph">';
                $paragraphOpen = true;
            }
            $resultsHtml .= '<span class="sup verseNum">' . htmlspecialchars($rowVerse) . '</span>';
            $resultsHtml .= '<span class="text verseText">' . htmlspecialchars($rowText) . '</span>';
        }
        if ($paragraphOpen) {
            $resultsHtml .= '</p>';
        }
        $resultsHtml .= '</div>';

        $errorsHtml = '<div class="errors bibleQuote">';
        if (!empty($ctx->errors)) {
            $errorsHtml .= '<table id="errorsTbl" class="errorsTbl">';
            foreach ($ctx->errors as $err) {
                $errorsHtml .= '<tr class="errorsRow">';
                $errorsHtml .= '<td class="errNum">errNum</td><td class="errNumVal">' . $err['errNum'] . '</td>';
                $errorsHtml .= '<td class="errMessage">errMessage</td><td class="errMessageVal">' . htmlspecialchars($err['errMessage']) . '</td>';
                $errorsHtml .= '</tr>';
            }
            $errorsHtml .= '</table>';
        }
        $errorsHtml .= '</div>';

        $infoHtml = '<div class="info bibleQuote">';
        $infoHtml .= '<input type="hidden" name="ENDPOINT_VERSION" value="' . QuoteContext::ENDPOINT_VERSION . '" class="BibleGetInfo">';
        $infoHtml .= '<input type="hidden" name="detectedNotation" value="' . htmlspecialchars($ctx->detectedNotation) . '" class="BibleGetInfo">';
        $versionsJson = json_encode($bibleVersionsInfo);
        $infoHtml .= '<input type="hidden" name="bibleVersionsInfo" value="' . htmlspecialchars($versionsJson !== false ? $versionsJson : '{}') . '" class="BibleGetInfo">';
        $infoHtml .= '</div>';

        return $resultsHtml . $errorsHtml . $infoHtml;
    }

    private static function createEmptyResponse(ServerRequestInterface $request): ResponseInterface
    {
        return new \Nyholm\Psr7\Response(
            200,
            [],
            null,
            $request->getProtocolVersion(),
            'OK'
        );
    }
}
