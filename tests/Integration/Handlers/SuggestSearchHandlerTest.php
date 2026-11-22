<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Handlers;

use BibleGet\Api\Handlers\SuggestSearchHandler;
use BibleGet\Api\Http\Enum\AcceptHeader;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/** /search/suggest: an edition's words that start with a prefix, most frequent first (search_vocabulary, 058). */
final class SuggestSearchHandlerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SuggestSearchHandler::resetCache();
        $pdo = $this->getConnection();
        if ($pdo->query("SELECT to_regclass('public.search_vocabulary')")?->fetchColumn() === null) {
            $pdo->exec((string) file_get_contents(__DIR__ . '/../../../migrations/058-search-vocabulary.sql'));
        }
    }

    /**
     * @param array<string, string> $params
     * @return list<array{word: string, count: int}>
     */
    private function suggest(array $params): array
    {
        $handler = new SuggestSearchHandler(new Psr17Factory());
        $handler->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::OPTIONS])
            ->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA])
            ->setAllowedAcceptHeaders([AcceptHeader::JSON]);
        $response = $handler->handle(( new ServerRequest('GET', '/v3/search/suggest') )->withQueryParams($params));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['suggestions']);
        self::assertArrayHasKey('ENDPOINT_VERSION', $body['info']);
        /** @var list<array{word: string, count: int}> */
        return $body['suggestions'];
    }

    public function testSuggestsWordsStartingWithThePrefix(): void
    {
        $words = $this->suggest(['prefix' => 'lig', 'version' => 'TEST1']);
        self::assertContains('light', array_column($words, 'word'));
        foreach ($words as $w) {
            self::assertStringStartsWith('lig', $w['word']);
            self::assertIsInt($w['count']);
        }
        $counts = array_column($words, 'count');
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts);
    }

    public function testPrefixIsFoldedAndCaseInsensitive(): void
    {
        $plain = $this->suggest(['prefix' => 'isr', 'version' => 'VGCL']);
        self::assertContains('israël', array_column($plain, 'word'));
        self::assertSame($plain, $this->suggest(['prefix' => 'ISR', 'version' => 'VGCL']));
        self::assertSame($plain, $this->suggest(['prefix' => 'Ísr', 'version' => 'VGCL']));
    }

    public function testLimitDefaultsToEightAndCapsAtTwenty(): void
    {
        // The fixtures are too small for 20 words with one prefix: 25 made-up ones, removed afterwards.
        $pdo = $this->getConnection();
        $pdo->exec("INSERT INTO search_vocabulary (sigla, word, folded, freq)
                    SELECT 'TEST1', 'zzword' || i, 'zzword' || i, i FROM generate_series(1, 25) i");
        try {
            self::assertCount(8, $this->suggest(['prefix' => 'zz', 'version' => 'TEST1']));
            self::assertCount(20, $this->suggest(['prefix' => 'zz', 'version' => 'TEST1', 'limit' => '500']));
            self::assertSame('zzword25', $this->suggest(['prefix' => 'zz', 'version' => 'TEST1'])[0]['word']);
        } finally {
            $pdo->exec("DELETE FROM search_vocabulary WHERE word LIKE 'zzword%'");
        }
    }

    public function testPercentAndUnderscoreAreNotWildcards(): void
    {
        $this->expectException(ValidationException::class);
        $this->suggest(['prefix' => 'l%', 'version' => 'TEST1']);
    }

    public function testShortPrefixIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->suggest(['prefix' => 'l', 'version' => 'TEST1']);
    }

    public function testUnknownVersionIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->suggest(['prefix' => 'lig', 'version' => 'NOPE']);
    }

    public function testSeveralVersionsAreRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->suggest(['prefix' => 'lig', 'version' => 'TEST1,DRB']);
    }

    public function testThePrefixIsLookedUpThroughTheIndex(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec('SET enable_seqscan = off');
        try {
            $stmt = $pdo->prepare('EXPLAIN ' . SuggestSearchHandler::QUERY);
            $stmt->execute(['TEST1', SuggestSearchHandler::foldedPattern($pdo, 'Lig'), 8]);
            $plan = implode("\n", $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            $pdo->exec('SET enable_seqscan = on');
        }
        // The word's prefix is part of the index condition, not a filter on every word of the edition.
        self::assertMatchesRegularExpression('/Index Cond:.*folded ~>=~/s', $plan, $plan);
    }
}
