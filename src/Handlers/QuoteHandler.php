<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Http\Exception\ForbiddenException;
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
            throw new ForbiddenException('Automated bot access is not permitted.');
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
            self::safeEncodeHeaders($request->getHeaders())
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
     * @param array<int, array<string, mixed>> $results
     */
    private function buildHtmlResults(array $results): string
    {
        $html = '<div class="results bibleQuote">';
        $version = $book = $chapter = '';
        $paragraphOpen = false;

        foreach ($results as $row) {
            $rowVersion = isset($row['version']) && is_scalar($row['version']) ? (string) $row['version'] : '';
            $rowBook    = isset($row['book']) && is_scalar($row['book']) ? (string) $row['book'] : '';
            $rowChapter = isset($row['chapter']) && is_scalar($row['chapter']) ? (string) $row['chapter'] : '';
            $rowVerse   = isset($row['verse']) && is_scalar($row['verse']) ? (string) $row['verse'] : '';
            $rowText    = isset($row['text']) && is_scalar($row['text']) ? (string) $row['text'] : '';

            if ($rowVersion !== $version) {
                if ($paragraphOpen) {
                    $html .= '</p>';
                    $paragraphOpen = false;
                }
                $version = $rowVersion;
                $html .= '<p class="version bibleVersion">' . htmlspecialchars($version) . '</p>';
                $book = '';
                $chapter = '';
            }
            if ($rowBook !== $book || $rowChapter !== $chapter) {
                if ($paragraphOpen) {
                    $html .= '</p>';
                }
                $book    = $rowBook;
                $chapter = $rowChapter;
                $html .= '<p class="book bookChapter">' . htmlspecialchars($book) . '&nbsp;' . htmlspecialchars($chapter) . '</p>';
                $html .= '<p class="verses versesParagraph">';
                $paragraphOpen = true;
            }
            $html .= '<span class="sup verseNum">' . htmlspecialchars($rowVerse) . '</span>';
            $html .= '<span class="text verseText">' . htmlspecialchars($rowText) . '</span>';
        }
        if ($paragraphOpen) {
            $html .= '</p>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * @param array<array{errNum: int, errMessage: string}> $errors
     */
    private function buildHtmlErrors(array $errors): string
    {
        $html = '<div class="errors bibleQuote">';
        if (!empty($errors)) {
            $html .= '<table id="errorsTbl" class="errorsTbl">';
            foreach ($errors as $err) {
                $html .= '<tr class="errorsRow">';
                $html .= '<td class="errNum">errNum</td><td class="errNumVal">' . $err['errNum'] . '</td>';
                $html .= '<td class="errMessage">errMessage</td><td class="errMessageVal">' . htmlspecialchars($err['errMessage']) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</table>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * @param array<string, string> $bibleVersionsInfo
     */
    private function buildHtmlInfo(string $detectedNotation, array $bibleVersionsInfo): string
    {
        $html = '<div class="info bibleQuote">';
        $html .= '<input type="hidden" name="ENDPOINT_VERSION" value="' . QuoteContext::ENDPOINT_VERSION . '" class="BibleGetInfo">';
        $html .= '<input type="hidden" name="detectedNotation" value="' . htmlspecialchars($detectedNotation) . '" class="BibleGetInfo">';
        $versionsJson = json_encode($bibleVersionsInfo);
        $html .= '<input type="hidden" name="bibleVersionsInfo" value="' . htmlspecialchars($versionsJson !== false ? $versionsJson : '{}') . '" class="BibleGetInfo">';
        $html .= '</div>';
        return $html;
    }

    /**
     * @param array<string, string> $bibleVersionsInfo
     */
    private function buildHtmlResponse(QuoteContext $ctx, array $bibleVersionsInfo): string
    {
        return $this->buildHtmlResults($ctx->results)
            . $this->buildHtmlErrors($ctx->errors)
            . $this->buildHtmlInfo($ctx->detectedNotation, $bibleVersionsInfo);
    }

    /**
     * Encode request headers as JSON, redacting sensitive values.
     *
     * @param array<array<string>> $headers
     */
    private static function safeEncodeHeaders(array $headers): string
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'set-cookie', 'x-api-key', 'proxy-authorization'];
        $safe = [];
        foreach ($headers as $name => $values) {
            if (in_array(strtolower($name), $sensitiveHeaders, true)) {
                $safe[$name] = ['[REDACTED]'];
            } else {
                $safe[$name] = $values;
            }
        }
        return json_encode($safe) ?: '{}';
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
