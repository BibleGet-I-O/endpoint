<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Handlers\QuoteHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\ForbiddenException;
use BibleGet\Api\Http\Exception\BadRequestException;
use BibleGet\Tests\Integration\DatabaseTestCase;
use BibleGet\Tests\Support\SyntheticNotes;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/** /v3/quote?footnotes=none|markers|full (footnotes spec, Part 3), on synthetic notes. */
final class QuoteNotesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->getConnection();
        $pdo->exec("DELETE FROM note WHERE edition IN ('TEST1', 'TEST2')");
        // Gen 1:1: a whole-verse footnote, a cross-reference after "beginning", a footnote on the heading.
        SyntheticNotes::add($pdo, 'TEST1', '01001001-2', 'crossref', 'a', [[1, 1, 1, null, null, null, 'text', 16]], [[43, 1, 1, 0, 6, 43, 1, 3]], 'Jn 1:1-3.', '1:1');
        SyntheticNotes::add($pdo, 'TEST1', '01001001-1', 'footnote', '*', [[1, 1, 1, null, null, null, 'text', null]], [], 'On the first verse.', '1:1');
        SyntheticNotes::add($pdo, 'TEST1', '01001001-3', 'footnote', '*', [[1, 1, 1, null, null, null, 'title1', 0]]);
        // A note printed at two places, both on the page: one note, two markers.
        SyntheticNotes::add($pdo, 'TEST1', '01001002-1', 'footnote', '*', [[1, 1, 2, null, null, null, 'text', 3], [1, 1, 3, null, null, null, 'text', 4]]);
        // Same verse numbers, other identities: a lettered sub-verse, a verse printed in chapter 2. Never on 1:1.
        SyntheticNotes::add($pdo, 'TEST1', '01001001a-1', 'footnote', '*', [[1, 1, 1, 'a']]);
        SyntheticNotes::add($pdo, 'TEST1', '01001001p-1', 'footnote', '*', [[1, 1, 1, null, null, 2]]);
        // Esther's two texts share verse numbers: a GREEK anchor is not the NULL-tradition row's.
        SyntheticNotes::add($pdo, 'TEST1', '01001001g-1', 'footnote', '*', [[1, 1, 1, null, 'GREEK']]);
        // Another edition's note on the same verse.
        SyntheticNotes::add($pdo, 'TEST2', '01001001-1', 'footnote', '*', [[1, 1, 1]]);
    }

    protected function tearDown(): void
    {
        $this->getConnection()->exec("DELETE FROM note WHERE edition IN ('TEST1', 'TEST2')");
        parent::tearDown();
    }

    private static function reader(string $scope = 'reader'): Credential
    {
        return new Credential(CredentialType::Project, 'k1', $scope, null, 'a1', null, null, true);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function quote(array $params, ?Credential $credential, string $accept = 'application/json'): array
    {
        $handler = new QuoteHandler(new Psr17Factory());
        $handler->setAllowedRequestMethods([RequestMethod::GET])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::HTML]);
        $request = ( new ServerRequest('GET', '/v3/quote', ['Accept' => $accept]) )->withQueryParams($params);
        if ($credential !== null) {
            $request = $request->withAttribute('credential', $credential);
        }
        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private static function rows(array $body): array
    {
        $rows = $body['results'] ?? null;
        self::assertIsArray($rows);
        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    public function testWithoutTheParameterNothingChanges(): void
    {
        $plain = $this->quote(['query' => 'Gen1:1-3', 'version' => 'TEST1'], self::reader());
        $none  = $this->quote(['query' => 'Gen1:1-3', 'version' => 'TEST1', 'footnotes' => 'none'], self::reader());
        self::assertArrayNotHasKey('notes', $plain);
        self::assertArrayNotHasKey('notes', self::rows($plain)[0]);
        self::assertSame($plain['results'], $none['results']);
        self::assertArrayNotHasKey('notes', $none);
    }

    public function testMarkersAreOrderedByFieldThenPosition(): void
    {
        $body = $this->quote(['query' => 'Gen1:1-3', 'version' => 'TEST1', 'footnotes' => 'markers'], self::reader());
        self::assertArrayNotHasKey('notes', $body, 'markers: no bodies');
        $rows = self::rows($body);
        self::assertSame(
            [
                ['id' => 'TEST1:01001001-3', 'kind' => 'footnote', 'marker' => '*', 'field' => 'title1', 'position' => 0],
                ['id' => 'TEST1:01001001-1', 'kind' => 'footnote', 'marker' => '*', 'field' => 'text', 'position' => null],
                ['id' => 'TEST1:01001001-2', 'kind' => 'crossref', 'marker' => 'a', 'field' => 'text', 'position' => 16],
            ],
            $rows[0]['notes'],
            'anchorsMatchByIdentityIncludingNulls: the sub-verse, GREEK and printed-in-2 notes stay off 1:1'
        );
        self::assertSame(['TEST1:01001002-1'], array_column(self::notes($rows[1]), 'id'));
        self::assertSame(['TEST1:01001002-1'], array_column(self::notes($rows[2]), 'id'));
    }

    public function testARowWithoutNotesGetsAnEmptyList(): void
    {
        $rows = self::rows($this->quote(['query' => 'Gen1:4', 'version' => 'TEST1', 'footnotes' => 'markers'], self::reader()));
        self::assertSame([], $rows[0]['notes']);
    }

    public function testEachEditionGetsItsOwnNotes(): void
    {
        $rows      = self::rows($this->quote(['query' => 'Gen1:1', 'version' => 'TEST1,TEST2', 'footnotes' => 'markers'], self::reader()));
        $byVersion = [];
        foreach ($rows as $r) {
            $byVersion[$r['version']] = array_column(self::notes($r), 'id');
        }
        self::assertSame(['TEST1:01001001-3', 'TEST1:01001001-1', 'TEST1:01001001-2'], $byVersion['TEST1']);
        self::assertSame(['TEST2:01001001-1'], $byVersion['TEST2']);
    }

    public function testFullListsEachNoteOnceWithBodiesAndRefs(): void
    {
        $body = $this->quote(['query' => 'Gen1:1-3', 'version' => 'TEST1', 'footnotes' => 'full'], self::reader());
        self::assertIsArray($body['notes']);
        self::assertEqualsCanonicalizing(
            ['TEST1:01001001-1', 'TEST1:01001001-2', 'TEST1:01001001-3', 'TEST1:01001002-1'],
            array_keys($body['notes'])
        );
        self::assertSame(
            [
                'kind'   => 'crossref',
                'marker' => 'a',
                'label'  => '1:1',
                'title'  => null,
                'body'   => 'Jn 1:1-3.',
                'refs'   => [['start' => ['book' => 43, 'chapter' => 1, 'verse' => 1, 'segment' => null, 'printed_chapter' => null], 'end' => ['book' => 43, 'chapter' => 1, 'verse' => 3], 'span' => [0, 6]]],
            ],
            $body['notes']['TEST1:01001001-2']
        );
        self::assertCount(3, self::rows($body)[0]['notes'], 'full keeps the markers on the rows');
    }

    public function testFullWithNoNotesIsAnEmptyObject(): void
    {
        $handler = new QuoteHandler(new Psr17Factory());
        $handler->setAllowedRequestMethods([RequestMethod::GET])->setAllowedAcceptHeaders([AcceptHeader::JSON]);
        $request = ( new ServerRequest('GET', '/v3/quote') )
            ->withQueryParams(['query' => 'Gen1:4', 'version' => 'TEST1', 'footnotes' => 'full'])
            ->withAttribute('credential', self::reader());
        self::assertStringContainsString('"notes":{}', (string) $handler->handle($request)->getBody());
    }

    public function testInvalidValueIs400(): void
    {
        $this->expectException(BadRequestException::class);
        $this->quote(['query' => 'Gen1:1', 'version' => 'TEST1', 'footnotes' => 'all'], self::reader());
    }

    public function testANonStringValueIs400(): void
    {
        $this->expectException(BadRequestException::class);
        $handler = new QuoteHandler(new Psr17Factory());
        $handler->setAllowedRequestMethods([RequestMethod::GET])->setAllowedAcceptHeaders([AcceptHeader::JSON]);
        $handler->handle(( new ServerRequest('GET', '/v3/quote') )
            ->withQueryParams(['query' => 'Gen1:1', 'version' => 'TEST1', 'footnotes' => ['full']])
            ->withAttribute('credential', self::reader()));
    }

    public function testAKeyOtherThanReaderIs403(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('reader key');
        $this->quote(['query' => 'Gen1:1', 'version' => 'TEST1', 'footnotes' => 'markers'], self::reader('read'));
    }

    public function testNoCredentialIs403(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->quote(['query' => 'Gen1:1', 'version' => 'TEST1', 'footnotes' => 'full'], null);
    }

    public function testXmlIs400(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('JSON only');
        $this->quote(['query' => 'Gen1:1', 'version' => 'TEST1', 'footnotes' => 'markers', 'return' => 'xml'], self::reader());
    }

    public function testHtmlAcceptIs400(): void
    {
        $this->expectException(BadRequestException::class);
        $this->quote(['query' => 'Gen1:1', 'version' => 'TEST1', 'footnotes' => 'markers'], self::reader(), 'text/html');
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private static function notes(array $row): array
    {
        $n = $row['notes'] ?? null;
        self::assertIsArray($n);
        /** @var list<array<string, mixed>> $n */
        return $n;
    }
}
