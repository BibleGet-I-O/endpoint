<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Http\Exception\ForbiddenException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\QueryFormulator;
use BibleGet\Api\Pipeline\QueryExecutor;
use BibleGet\Api\Util\FormattedText;
use BibleGet\Api\Util\SearchUtils;
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
            $stringParams[$key] = is_string($value) ? $value : ( is_scalar($value) ? (string) $value : '' );
        }
        $credential = $request->getAttribute('credential');
        $ctx        = new QuoteContext(
            $stringParams,
            LoggerFactory::create('api'),
            $request->getHeaderLine('Origin'),
            $request->getMethod(),
            $credential instanceof Credential ? $credential : null
        );
        $ctx->initialize();

        $queryRaw = $params['query'] ?? '';
        if (!is_string($queryRaw) || $queryRaw === '') {
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

        // Per-version 1-based canonical_order (subverse-aware), sort_order stripped.
        SearchUtils::assignCanonicalOrder($ctx->results);

        // Build response body
        $response = $this->buildResponse($response, $contentType, $ctx);
        return $this->withCacheHeaders($request, $response);
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
            $body          = new \stdClass();
            $body->results = $ctx->results;
            $body->errors  = $ctx->errors;
            $body->notices = $ctx->notices;
            $body->info    = [
                'ENDPOINT_VERSION'  => QuoteContext::ENDPOINT_VERSION,
                'detectedNotation'  => $ctx->detectedNotation,
                'bibleVersionsInfo' => $bibleVersionsInfo,
            ];
            return $this->jsonResponse($response, $body);
        }

        if ($contentType === 'application/xml') {
            $root    = '<?xml version="1.0" encoding="UTF-8"?><BibleQuote/>';
            $xml     = new \SimpleXMLElement($root);
            $errors  = $xml->addChild('errors');
            $notices = $xml->addChild('notices');
            $info    = $xml->addChild('info');
            $results = $xml->addChild('results');

            $info->addAttribute('ENDPOINT_VERSION', QuoteContext::ENDPOINT_VERSION);
            $info['detectedNotation']  = $ctx->detectedNotation;
            $encoded                   = json_encode($bibleVersionsInfo);
            $info['bibleVersionsInfo'] = $encoded !== false ? $encoded : '{}';

            foreach ($ctx->errors as $err) {
                $errNode = $errors->addChild('error', htmlspecialchars($err['errMessage'], ENT_XML1, 'UTF-8'));
                $errNode->addAttribute('errNum', (string) $err['errNum']);
            }

            foreach ($ctx->notices as $notice) {
                $noticeNode = $notices->addChild('notice', htmlspecialchars($notice['message'], ENT_XML1, 'UTF-8'));
                foreach (['code', 'version', 'book', 'chapter', 'verse_from', 'verse_to', 'reference'] as $key) {
                    $noticeNode->addAttribute($key, (string) $notice[$key]);
                }
            }

            foreach ($ctx->results as $row) {
                $resultNode = $results->addChild('result');
                foreach ($row as $key => $value) {
                    $resultNode[$key] = is_bool($value)
                        ? ( $value ? 'true' : 'false' )
                        : ( is_scalar($value) ? (string) $value : '' );
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
        $html          = '<div class="results bibleQuote">';
        $version       = $book = $chapter = '';
        $paragraphOpen = false;

        foreach ($results as $row) {
            $rowVersion = isset($row['version']) && is_scalar($row['version']) ? (string) $row['version'] : '';
            $rowBook    = isset($row['book']) && is_scalar($row['book']) ? (string) $row['book'] : '';
            $rowChapter = isset($row['chapter']) && is_scalar($row['chapter']) ? (string) $row['chapter'] : '';
            $rowVerse   = isset($row['verse']) && is_scalar($row['verse']) ? (string) $row['verse'] : '';
            $rowText    = isset($row['text']) && is_scalar($row['text']) ? (string) $row['text'] : '';

            if ($rowVersion !== $version) {
                if ($paragraphOpen) {
                    $html         .= '</p>';
                    $paragraphOpen = false;
                }
                $version = $rowVersion;
                $html   .= '<p class="version bibleVersion">' . htmlspecialchars($version) . '</p>';
                $book    = '';
                $chapter = '';
            }
            if ($rowBook !== $book || $rowChapter !== $chapter) {
                if ($paragraphOpen) {
                    $html .= '</p>';
                }
                $book          = $rowBook;
                $chapter       = $rowChapter;
                $html         .= '<p class="book bookChapter">' . htmlspecialchars($book) . '&nbsp;' . htmlspecialchars($chapter) . '</p>';
                $html         .= '<p class="verses versesParagraph">';
                $paragraphOpen = true;
            }
            $html .= '<span class="sup verseNum"' . self::placementAttributes($row) . '>' . htmlspecialchars($rowVerse) . '</span>';
            // Formatted text (poetic lines, small caps, italics) as HTML, escaped otherwise.
            $html .= '<span class="text verseText">' . FormattedText::toHtml($rowText) . '</span>';
        }
        if ($paragraphOpen) {
            $html .= '</p>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Data attributes on the verse number for how the edition prints the row,
     * only where it differs from the default (printed_chapter, verse_label,
     * omitted). An omitted verse's text is empty, so its text span is too.
     *
     * @param array<string, mixed> $row
     */
    private static function placementAttributes(array $row): string
    {
        $attrs = '';
        if (is_int($row['printed_chapter'] ?? null)) {
            $attrs .= ' data-printed-chapter="' . $row['printed_chapter'] . '"';
        }
        if (is_string($row['verse_label'] ?? null)) {
            $attrs .= ' data-verse-label="' . htmlspecialchars($row['verse_label']) . '"';
        }
        if (( $row['omitted'] ?? false ) === true) {
            $attrs .= ' data-omitted="true"';
        }
        return $attrs;
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
                $html .= '<td class="errNum">errNum</td><td class="errNumVal">' . (int) $err['errNum'] . '</td>';
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
     * @param list<array<string, int|string>> $notices
     */
    private function buildHtmlInfo(string $detectedNotation, array $bibleVersionsInfo, array $notices = []): string
    {
        $html         = '<div class="info bibleQuote">';
        $html        .= '<input type="hidden" name="ENDPOINT_VERSION" value="' . QuoteContext::ENDPOINT_VERSION . '" class="BibleGetInfo">';
        $html        .= '<input type="hidden" name="detectedNotation" value="' . htmlspecialchars($detectedNotation) . '" class="BibleGetInfo">';
        $versionsJson = json_encode($bibleVersionsInfo);
        $html        .= '<input type="hidden" name="bibleVersionsInfo" value="' . htmlspecialchars($versionsJson !== false ? $versionsJson : '{}') . '" class="BibleGetInfo">';
        // Notices are informational: hidden, like the info above, so the clients that
        // insert this HTML into pages (WordPress, MediaWiki) show nothing new.
        $noticesJson = json_encode($notices);
        $html       .= '<input type="hidden" name="notices" value="' . htmlspecialchars($noticesJson !== false ? $noticesJson : '[]') . '" class="BibleGetNotices">';
        $html       .= '</div>';
        return $html;
    }

    /**
     * @param array<string, string> $bibleVersionsInfo
     */
    private function buildHtmlResponse(QuoteContext $ctx, array $bibleVersionsInfo): string
    {
        return $this->buildHtmlResults($ctx->results)
            . $this->buildHtmlErrors($ctx->errors)
            . $this->buildHtmlInfo($ctx->detectedNotation, $bibleVersionsInfo, $ctx->notices);
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
