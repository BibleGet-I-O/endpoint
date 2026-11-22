<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Services\EmbeddingClient;
use BibleGet\Api\Services\SemanticSearchService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SemanticSearchServiceTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function queries(): array
    {
        return [
            // LaBSE is cased: "Nemico" splits into pieces that match "Ne…"/"Non…" verses, "nemico" finds enemies.
            'capitalised word'  => ['Nemico', 'nemico'],
            'all capitals'      => ['NEMICO', 'nemico'],
            'accented capitals' => ['ÉGLISE', 'église'],
            'sentence'          => ['Come devo pregare?', 'come devo pregare?'],
            'already lowercase' => ['love your enemies', 'love your enemies'],
            'uncased script'    => ['爱你们的仇敌', '爱你们的仇敌'],
        ];
    }

    #[DataProvider('queries')]
    public function testTheQueryIsLowercasedBeforeItIsEmbedded(string $typed, string $embedded): void
    {
        $client = $this->createMock(EmbeddingClient::class);
        $client->expects(self::once())->method('embed')->with($embedded)->willReturn([0.1, 0.2]);
        $client->method('getLastModel')->willReturn('');

        $service = new SemanticSearchService($this->createStub(\PDO::class), new NullLogger());

        self::assertSame([0.1, 0.2], $service->embedQuery($client, $typed, 'labse', [], 'embedding_labse'));
    }
}
