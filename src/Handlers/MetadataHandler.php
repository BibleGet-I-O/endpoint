<?php

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Pipeline\QuoteContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MetadataHandler extends AbstractHandler
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

        $mysqli = Connection::getConnection();

        // Determine metadata sub-resource from path or legacy `query` param
        $subResourceRaw = $this->requestPathParams[0] ?? $params['query'] ?? '';
        $subResource = is_string($subResourceRaw) ? $subResourceRaw : '';

        switch ($subResource) {
            case 'biblebooks':
                $data = $this->getBibleBooks($mysqli);
                break;
            case 'bibleversions':
                $data = $this->getBibleVersions($mysqli, 'BIBLE');
                break;
            case 'literatureversions':
                $data = $this->getBibleVersions($mysqli, 'LITERATURE');
                break;
            case 'versionindex':
                $versionsRaw = $params['versions'] ?? '';
                $versionsStr = is_string($versionsRaw) ? $versionsRaw : '';
                $data = $this->getVersionIndex($mysqli, $versionsStr);
                break;
            default:
                throw new NotFoundException('Unknown metadata query: ' . $subResource);
        }

        $data['info'] = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        if ($contentType === 'application/json') {
            return $this->jsonResponse($response, $data);
        }

        if ($contentType === 'application/xml') {
            return $this->xmlResponse($response, $this->toXml($data, $subResource));
        }

        // HTML fallback
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $this->htmlResponse($response, '<pre>' . htmlspecialchars($json !== false ? $json : '{}') . '</pre>');
    }

    /**
     * @return array<string, mixed>
     */
    private function getBibleBooks(\mysqli $mysqli): array
    {
        $biblebooks = [];
        $result1 = $mysqli->query('SELECT * FROM biblebooks_fullname');
        if (!$result1 instanceof \mysqli_result) {
            throw new InternalServerErrorException('MySQL ERROR ' . $mysqli->errno . ': ' . $mysqli->error);
        }

        $cols  = mysqli_num_fields($result1);
        $names = [];
        $finfo = mysqli_fetch_fields($result1);
        foreach ($finfo as $val) {
            $names[] = $val->name;
        }

        $result2 = $mysqli->query('SELECT * FROM biblebooks_abbr');
        if (!$result2 instanceof \mysqli_result) {
            throw new InternalServerErrorException('MySQL ERROR ' . $mysqli->errno . ': ' . $mysqli->error);
        }

        $n = 0;
        while ($row1 = mysqli_fetch_assoc($result1)) {
            $row2 = mysqli_fetch_assoc($result2);
            $biblebooks[$n] = [];
            for ($x = 0; $x < $cols - 1; $x++) {
                $val1 = (string) ($row1[$names[$x + 1]] ?? '');
                $val2 = (string) ($row2[$names[$x + 1]] ?? '');
                $temparray = [$val1, $val2];
                $arr1 = explode(' | ', $val1);
                $booknames = array_map(fn($s) => QuoteContext::toProperCase(preg_replace('/\s+/', '', trim($s)) ?? trim($s)), $arr1);
                $arr2 = explode(' | ', $val2);
                $abbrevs = count($arr2) > 1 ? array_map(fn($s) => QuoteContext::toProperCase(preg_replace('/\s+/', '', trim($s)) ?? trim($s)), $arr2) : [];
                $biblebooks[$n][$x] = array_merge($temparray, $booknames, $abbrevs);
            }
            $n++;
        }

        $languages = $names;
        array_shift($languages);

        return [
            'results'   => $biblebooks,
            'languages' => $languages,
            'errors'    => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getBibleVersions(\mysqli $mysqli, string $type = ''): array
    {
        $validversions          = [];
        $validversions_fullname = [];
        $copyrightversions      = [];

        $querystring = 'SELECT * FROM versions_available';
        if ($type !== '') {
            $querystring .= " WHERE type='" . $mysqli->real_escape_string($type) . "'";
        }

        $result = $mysqli->query($querystring);
        if (!$result instanceof \mysqli_result) {
            throw new InternalServerErrorException('MySQL ERROR ' . $mysqli->errno . ': ' . $mysqli->error);
        }

        while ($row = $result->fetch_assoc()) {
            $info = [
                $row['fullname'], $row['year'], $row['language'],
                $row['imprimatur'], $row['canon'],
                $row['copyright_holder'], $row['notes'],
            ];
            $validversions_fullname[(string) $row['sigla']] = implode('|', $info);
            $validversions[] = $row['sigla'];
            if ($row['copyright'] == 1) {
                $copyrightversions[] = $row['sigla'];
            }
        }

        return [
            'validversions'          => $validversions,
            'validversions_fullname' => $validversions_fullname,
            'copyrightversions'      => $copyrightversions,
            'errors'                 => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getVersionIndex(\mysqli $mysqli, string $versionsStr): array
    {
        if ($versionsStr === '') {
            throw new ValidationException('The versions parameter is required for versionindex queries.');
        }

        // Get valid versions
        $allValid = [];
        $result = $mysqli->query('SELECT sigla FROM versions_available');
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $allValid[] = $row['sigla'];
            }
        }

        $versions = array_filter(explode(',', $versionsStr), fn($v) => in_array($v, $allValid));
        if (empty($versions)) {
            throw new ValidationException('No valid versions in the request.');
        }

        $indexes = [];
        foreach ($versions as $variant) {
            $abbreviations = $bbbooks = $chapter_limit = $verse_limit = $book_num = [];
            $result = $mysqli->query('SELECT * FROM ' . $variant . '_idx');
            if ($result instanceof \mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $abbreviations[]  = $row['abbrev'];
                    $bbbooks[]        = $row['fullname'];
                    $chapter_limit[]  = (int) $row['chapters'];
                    $verse_limit[]    = array_map('intval', explode(',', (string) $row['verses_last']));
                    $book_num[]       = (int) $row['book'];
                }
            }
            $indexes[$variant] = [
                'abbreviations' => $abbreviations,
                'biblebooks'    => $bbbooks,
                'chapter_limit' => $chapter_limit,
                'verse_limit'   => $verse_limit,
                'book_num'      => $book_num,
            ];
        }

        return [
            'indexes' => $indexes,
            'errors'  => [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toXml(array $data, string $rootName): string
    {
        $root = '<?xml version="1.0" encoding="UTF-8"?><BibleGetMetadata/>';
        $xml  = new \SimpleXMLElement($root);
        $xml->addChild('errors');
        $info = $xml->addChild('info');
        $info->addAttribute('ENDPOINT_VERSION', self::ENDPOINT_VERSION);

        // Simplified XML — encode data as JSON attributes for compatibility
        $results = $xml->addChild('results');
        $results->addAttribute('type', $rootName);
        $json = json_encode($data['results'] ?? $data['indexes'] ?? $data, JSON_UNESCAPED_UNICODE);
        $results->addAttribute('data', $json !== false ? $json : '{}');

        $xmlString = $xml->asXML();
        return $xmlString !== false ? $xmlString : '';
    }
}
