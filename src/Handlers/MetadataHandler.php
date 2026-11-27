<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Api\Http\Exception\ForbiddenException;
use BibleGet\Api\Repositories\EntityRepository;
use BibleGet\Api\Repositories\IntroductionRepository;
use BibleGet\Api\Repositories\OutlineRepository;
use BibleGet\Api\Util\AppVersion;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\InternalServerErrorException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Util\IndexList;
use BibleGet\Api\Util\StringUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class MetadataHandler extends AbstractHandler
{
    protected bool $carriesServiceNotices = true;

    private LoggerInterface $logger;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Nyholm\Psr7\Response(200, [], null, $request->getProtocolVersion(), 'OK');
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);
        $this->validateRequestContentType($request);
        $this->logger = LoggerFactory::create('api');

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
            case 'outline':
                $data = $this->getOutline($pdo, $params, $contentType, self::isReader($request));
                break;
            case 'introduction':
                $data = $this->getIntroduction($pdo, $params, $contentType, self::isReader($request));
                break;
            case 'entities':
                $data = $this->getEntities($pdo, $params, $contentType);
                break;
            default:
                throw new NotFoundException('Unknown metadata query: ' . $subResource);
        }

        $data['info'] = ['ENDPOINT_VERSION' => AppVersion::current()];

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

    private static function isReader(ServerRequestInterface $request): bool
    {
        $credential = $request->getAttribute('credential');
        return $credential instanceof Credential && $credential->scope === 'reader';
    }

    /**
     * The edition (upper case) and the universal book number (null when absent and $optional).
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: ?int}
     */
    private static function editionAndBook(\PDO $pdo, array $params, bool $optional = false): array
    {
        $version = strtoupper(StringUtils::asString($params['version'] ?? ''));
        $book    = StringUtils::asString($params['book'] ?? '');
        if (!( $optional && $book === '' ) && ( preg_match('/^[1-9]\d?$/', $book) !== 1 || (int) $book > 73 )) {
            throw new BadRequestException('The book parameter must be a universal book number, 1 to 73.');
        }
        if (!( new OutlineRepository($pdo) )->editionExists($version)) {
            throw new BadRequestException('The version parameter must be one of the available Bible versions.');
        }
        return [$version, $book === '' ? null : (int) $book];
    }

    /**
     * A book's titles with their levels; the edition's commentary titles, and which introduction the book has,
     * only for the reader key.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getOutline(\PDO $pdo, array $params, string $contentType, bool $reader): array
    {
        if ($contentType !== 'application/json') {
            throw new BadRequestException('The outline is available in JSON only.');
        }
        [$version, $book] = self::editionAndBook($pdo, $params);
        $book             = (int) $book;
        $data             = ['version' => $version, 'book' => $book, 'titles' => ( new OutlineRepository($pdo) )->titles($version, $book, $reader)];
        if ($reader) {
            $own                  = ( new IntroductionRepository($pdo) )->forBook($version, $book);
            $data['introduction'] = $own === null ? null : ['id' => $own['id'], 'title' => $own['title']];
        }
        return $data;
    }

    /**
     * An edition's index of the persons, places or events of CBPPEDR, each with its verses in the edition.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getEntities(\PDO $pdo, array $params, string $contentType): array
    {
        if ($contentType !== 'application/json') {
            throw new BadRequestException('The index of persons, places and events is available in JSON only.');
        }
        $type = StringUtils::asString($params['type'] ?? '');
        if (!in_array($type, ['person', 'place', 'event'], true)) {
            throw new BadRequestException('The type parameter must be person, place or event.');
        }
        [$version] = self::editionAndBook($pdo, $params, true);
        return ['edition' => $version, 'type' => $type] + ( new EntityRepository($pdo) )->index($version, $type);
    }

    /**
     * A book's introduction with the general ones that cover it (book=), or one introduction (id=); reader key only.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getIntroduction(\PDO $pdo, array $params, string $contentType, bool $reader): array
    {
        $id               = StringUtils::asString($params['id'] ?? '');
        [$version, $book] = self::editionAndBook($pdo, $params, $id !== '');
        if ($id !== '' && ( $book !== null || preg_match('/^[a-z0-9-]{1,40}$/', $id) !== 1 )) {
            throw new BadRequestException('Give either book (a universal book number) or id (an introduction\'s id), not both.');
        }
        if (!$reader) {
            throw new ForbiddenException('Introductions are available only with the BibleGet website\'s reader key.');
        }
        if ($contentType !== 'application/json') {
            throw new BadRequestException('Introductions are available in JSON only.');
        }
        $repository = new IntroductionRepository($pdo);
        if ($book === null) {
            $one = $repository->byId($version, $id);
            if ($one === null) {
                throw new NotFoundException('No introduction ' . $id . ' in ' . $version . '.');
            }
            return ['version' => $version, 'introduction' => $one];
        }
        return [
            'version'      => $version,
            'book'         => $book,
            'introduction' => $repository->forBook($version, $book),
            'general'      => $repository->generalFor($version, $book),
        ];
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
            $this->logger->error('Database error while handling metadata', ['exception_class' => get_class($e), 'context' => 'metadata_fetch']);
            throw new InternalServerErrorException('An internal database error occurred.');
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
            $this->logger->error('Database error while handling metadata', ['exception_class' => get_class($e), 'context' => 'metadata_fetch']);
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        if ($result2 === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }

        $n           = 0;
        $bookNumbers = [];
        while (is_array($row1 = $result1->fetch(\PDO::FETCH_ASSOC))) {
            $row2 = $result2->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row2)) {
                throw new InternalServerErrorException('biblebooks_abbr has fewer rows than biblebooks_fullname.');
            }
            if (( $row1['BOOK'] ?? null ) !== ( $row2['BOOK'] ?? null )) {
                throw new InternalServerErrorException('biblebooks_fullname and biblebooks_abbr BOOK keys are mismatched.');
            }
            $bookNumbers[]  = StringUtils::asInt($row1['BOOK'] ?? 0);
            $biblebooks[$n] = [];
            for ($x = 0; $x < $cols - 1; $x++) {
                $val1               = StringUtils::asString($row1[$names[$x + 1]] ?? '');
                $val2               = StringUtils::asString($row2[$names[$x + 1]] ?? '');
                $biblebooks[$n][$x] = StringUtils::bibleBookVariants($val1, $val2);
            }
            $n++;
        }

        $languages = $names;
        array_shift($languages);

        return [
            'results'   => $biblebooks,
            'languages' => $languages,
            'codes'     => $this->getBookCodes($pdo, $bookNumbers),
            'errors'    => [],
        ];
    }

    /**
     * USFM and OSIS codes (migration 046), index-aligned with the books.
     *
     * @param list<int> $bookNumbers The "BOOK" of each entry in `results`, in order.
     * @return list<array{usfm: string, osis: string}>
     */
    private function getBookCodes(\PDO $pdo, array $bookNumbers): array
    {
        try {
            $stmt = $pdo->query('SELECT book, usfm_code, osis_code FROM biblebooks_codes ORDER BY book');
        } catch (\PDOException $e) {
            $this->logger->error('Database error while handling metadata', ['exception_class' => get_class($e), 'context' => 'metadata_fetch']);
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        if ($stmt === false) {
            throw new InternalServerErrorException('An internal database error occurred.');
        }
        $byBook = [];
        while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $byBook[StringUtils::asInt($row['book'] ?? 0)] = [
                'usfm' => StringUtils::asString($row['usfm_code'] ?? ''),
                'osis' => StringUtils::asString($row['osis_code'] ?? ''),
            ];
        }
        $codes = [];
        foreach ($bookNumbers as $book) {
            if (!isset($byBook[$book])) {
                throw new InternalServerErrorException('biblebooks_codes has no row for book ' . $book . '.');
            }
            $codes[] = $byBook[$book];
        }
        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    private function getBibleVersions(\PDO $pdo, string $type = ''): array
    {
        $validversions          = [];
        $validversions_fullname = [];
        $copyrightversions      = [];
        $liturgicalversions     = [];

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
            if (filter_var($row['liturgical'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $liturgicalversions[] = $row['sigla'];
            }
        }

        return [
            'validversions'          => $validversions,
            'validversions_fullname' => $validversions_fullname,
            'copyrightversions'      => $copyrightversions,
            'liturgicalversions'     => $liturgicalversions,
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
            $abbreviations = $bbbooks = $chapter_limit = $verse_limit = $book_num = $prologue = [];
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
                $verse_limit[]   = IndexList::parse(StringUtils::asString($row['verses_last']), $this->logger, $variant . '_idx book ' . StringUtils::asString($row['book']));
                $book_num[]      = StringUtils::asInt($row['book']);
                $prologue[]      = isset($row['prologue_verses']) ? StringUtils::asInt($row['prologue_verses']) : 0;
            }
            $indexes[$variant] = [
                'abbreviations' => $abbreviations,
                'biblebooks'    => $bbbooks,
                'chapter_limit' => $chapter_limit,
                'verse_limit'   => $verse_limit,
                'book_num'      => $book_num,
                'prologue'      => $prologue,
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
        $info->addAttribute('ENDPOINT_VERSION', AppVersion::current());

        // Simplified XML — encode data as JSON in the @data attribute for backward
        // compatibility with existing consumers of the legacy XML format.
        // A future version could emit proper recursive XML elements instead.
        $results = $xml->addChild('results');
        $results->addAttribute('type', $rootName);
        $json = json_encode($data['results'] ?? $data['indexes'] ?? $data, JSON_UNESCAPED_UNICODE);
        $results->addAttribute('data', $json !== false ? $json : '{}');
        if (isset($data['codes'])) {
            $codesJson = json_encode($data['codes'], JSON_UNESCAPED_UNICODE);
            $results->addAttribute('codes', $codesJson !== false ? $codesJson : '[]');
        }

        $xmlString = $xml->asXML();
        return $xmlString !== false ? $xmlString : '';
    }
}
