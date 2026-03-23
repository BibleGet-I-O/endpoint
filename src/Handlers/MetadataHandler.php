<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Util\StringUtils;
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

        $pdo = Connection::getConnection();

        // Determine metadata sub-resource from path or legacy `query` param
        $subResourceRaw = $this->requestPathParams[0] ?? $params['query'] ?? '';
        $subResource    = is_string($subResourceRaw) ? $subResourceRaw : '';

        switch ($subResource) {
            case 'biblebooks':
                $data = $this->getBibleBooks($pdo);
                break;
            case 'bibleversions':
                $data = $this->getBibleVersions($pdo, 'BIBLE');
                break;
            case 'literatureversions':
                $data = $this->getBibleVersions($pdo, 'LITERATURE');
                break;
            case 'versionindex':
                $versionsRaw = $params['versions'] ?? '';
                $versionsStr = is_string($versionsRaw) ? $versionsRaw : '';
                $data        = $this->getVersionIndex($pdo, $versionsStr);
                break;
            default:
                throw new NotFoundException('Unknown metadata query: ' . $subResource);
        }

        $data['info'] = ['ENDPOINT_VERSION' => self::ENDPOINT_VERSION];

        if ($contentType === 'application/json') {
            $response = $this->jsonResponse($response, $data);
        } elseif ($contentType === 'application/xml') {
            $response = $this->xmlResponse($response, $this->toXml($data, $subResource));
        } else {
            // HTML fallback
            $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response = $this->htmlResponse($response, '<pre>' . htmlspecialchars($json !== false ? $json : '{}') . '</pre>');
        }

        return $this->withCacheHeaders($request, $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function getBibleBooks(\PDO $pdo): array
    {
        $biblebooks = [];
        try {
            $result1 = $pdo->query('SELECT * FROM biblebooks_fullname ORDER BY "BOOK"');
        } catch (\PDOException $e) {
            throw new InternalServerErrorException('Database error: ' . $e->getMessage());
        }
        if ($result1 === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }

        $cols  = $result1->columnCount();
        $names = [];
        for ($i = 0; $i < $cols; $i++) {
            $meta    = $result1->getColumnMeta($i);
            $names[] = $meta !== false ? $meta['name'] : '';
        }

        try {
            $result2 = $pdo->query('SELECT * FROM biblebooks_abbr ORDER BY "BOOK"');
        } catch (\PDOException $e) {
            throw new InternalServerErrorException('Database error: ' . $e->getMessage());
        }
        if ($result2 === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }

        $n = 0;
        while (is_array($row1 = $result1->fetch(\PDO::FETCH_ASSOC))) {
            $row2 = $result2->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row2)) {
                throw new InternalServerErrorException('biblebooks_abbr has fewer rows than biblebooks_fullname.');
            }
            if (( $row1['BOOK'] ?? null ) !== ( $row2['BOOK'] ?? null )) {
                throw new InternalServerErrorException('biblebooks_fullname and biblebooks_abbr BOOK keys are mismatched.');
            }
            $biblebooks[$n] = [];
            for ($x = 0; $x < $cols - 1; $x++) {
                $val1               = StringUtils::asString($row1[$names[$x + 1]] ?? '');
                $val2               = StringUtils::asString($row2[$names[$x + 1]] ?? '');
                $temparray          = [$val1, $val2];
                $arr1               = explode(' | ', $val1);
                $booknames          = array_map(fn($s) => StringUtils::toProperCase(trim($s)), $arr1);
                $arr2               = explode(' | ', $val2);
                $abbrevs            = count($arr2) > 1 ? array_map(fn($s) => StringUtils::toProperCase(trim($s)), $arr2) : [];
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
    private function getBibleVersions(\PDO $pdo, string $type = ''): array
    {
        $validversions          = [];
        $validversions_fullname = [];
        $copyrightversions      = [];

        if ($type !== '') {
            $stmt = $pdo->prepare('SELECT * FROM versions_available WHERE type = ? ORDER BY sigla');
            $stmt->execute([$type]);
        } else {
            $stmt = $pdo->query('SELECT * FROM versions_available ORDER BY sigla');
        }
        if ($stmt === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }

        while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $sigla                          = StringUtils::asString($row['sigla']);
            $info                           = [
                StringUtils::asString($row['fullname']),
                StringUtils::asString($row['year']),
                StringUtils::asString($row['language']),
                StringUtils::asString($row['imprimatur']),
                StringUtils::asString($row['canon']),
                StringUtils::asString($row['copyright_holder']),
                StringUtils::asString($row['notes']),
            ];
            $validversions_fullname[$sigla] = implode('|', $info);
            $validversions[]                = $row['sigla'];
            if (StringUtils::asInt($row['copyright']) === 1) {
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
    private function getVersionIndex(\PDO $pdo, string $versionsStr): array
    {
        if ($versionsStr === '') {
            throw new ValidationException('The versions parameter is required for versionindex queries.');
        }

        // Get valid versions
        $allValid = [];
        $result   = $pdo->query('SELECT sigla FROM versions_available');
        if ($result === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        while (is_array($row = $result->fetch(\PDO::FETCH_ASSOC))) {
            $allValid[] = $row['sigla'];
        }

        $versions = array_filter(explode(',', $versionsStr), fn($v) => in_array($v, $allValid));
        if (empty($versions)) {
            throw new ValidationException('No valid versions in the request.');
        }

        $indexes = [];
        foreach ($versions as $variant) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $variant)) {
                continue;
            }
            $abbreviations = $bbbooks = $chapter_limit = $verse_limit = $book_num = [];
            try {
                $result = $pdo->query('SELECT * FROM "' . $variant . '_idx" ORDER BY book');
            } catch (\PDOException) {
                throw new InternalServerErrorException('An internal database error occurred.');
            }
            if ($result === false) {
                throw new InternalServerErrorException('An internal database error occurred.');
            }
            while (is_array($row = $result->fetch(\PDO::FETCH_ASSOC))) {
                $abbreviations[] = $row['abbrev'];
                $bbbooks[]       = $row['fullname'];
                $chapter_limit[] = StringUtils::asInt($row['chapters']);
                $verse_limit[]   = array_map('intval', explode(',', StringUtils::asString($row['verses_last'])));
                $book_num[]      = StringUtils::asInt($row['book']);
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

        // Simplified XML — encode data as JSON in the @data attribute for backward
        // compatibility with existing consumers of the legacy XML format.
        // A future version could emit proper recursive XML elements instead.
        $results = $xml->addChild('results');
        $results->addAttribute('type', $rootName);
        $json = json_encode($data['results'] ?? $data['indexes'] ?? $data, JSON_UNESCAPED_UNICODE);
        $results->addAttribute('data', $json !== false ? $json : '{}');

        $xmlString = $xml->asXML();
        return $xmlString !== false ? $xmlString : '';
    }
}
