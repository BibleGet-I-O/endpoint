<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Pipeline;

use BibleGet\Api\Pipeline\QueryExecutor;
use BibleGet\Api\Pipeline\QueryFormulator;
use BibleGet\Api\Pipeline\QueryValidator;
use BibleGet\Api\Pipeline\QuoteContext;
use BibleGet\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #212: Latin citations name the Psalter in more than one case — "Psalmo"
 * (ablative, "ex Psalmo") in the Latin lectionary, "Psalmus" in the Nova
 * Vulgata's headings, "Psalmi", "Psalmorum" — and book names are matched
 * exactly, so each form must be stored (migration 050).
 */
final class LatinPsalmsBookNamesIntegrationTest extends DatabaseTestCase
{
    private function executePipeline(string $query, string $version): QuoteContext
    {
        $ctx = new QuoteContext(['query' => $query, 'version' => $version]);
        $ctx->initialize();
        $ctx->queryStrClean();

        $validator = new QueryValidator($ctx);
        $validator->validateQueries();

        if (!empty($ctx->validatedQueries)) {
            $formulator = new QueryFormulator($ctx);
            $formulator->formulateSQLQueries();

            $executor = new QueryExecutor($ctx);
            $executor->executeSQLQueries();
        }

        return $ctx;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function latinPsalmsNames(): array
    {
        return [
            'ablative (lectionary)'     => ['Psalmo'],
            'nominative (Nova Vulgata)' => ['Psalmus'],
            'genitive singular'         => ['Psalmi'],
            'genitive plural'           => ['Psalmorum'],
        ];
    }

    #[DataProvider('latinPsalmsNames')]
    public function testLatinNameOfThePsalmsResolvesToThePsalms(string $name): void
    {
        $ctx = $this->executePipeline($name . ' 51,3', 'TEST1');

        self::assertEmpty($ctx->errors, 'Expected no errors: ' . print_r($ctx->errors, true));
        self::assertCount(1, $ctx->results);
        self::assertSame('Psalms', $ctx->results[0]['book']); // TEST1's name for book 23
        self::assertEquals(51, $ctx->results[0]['chapter']);
        self::assertEquals(3, $ctx->results[0]['verse']);
    }
}
