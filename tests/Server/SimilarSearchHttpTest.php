<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class SimilarSearchHttpTest extends ServerTestCase
{
    public function testSimilarRouteExists(): void
    {
        $r = self::httpGet('/v3/search/similar?version=TEST1');
        // 422 = route exists but reference param missing
        self::assertSame(422, $r['status']);
    }

    public function testMissingReferenceReturns422(): void
    {
        $r = self::httpGet('/v3/search/similar?version=TEST1');
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('reference', $body['detail']);
    }

    public function testMissingVersionReturns422(): void
    {
        $r = self::httpGet('/v3/search/similar?reference=Gen1:1');
        self::assertSame(422, $r['status']);

        $body = self::jsonBody($r['body']);
        self::assertStringContainsString('version', $body['detail']);
    }

    public function testInvalidReferenceReturns422(): void
    {
        $r = self::httpGet('/v3/search/similar?reference=invalid&version=TEST1');
        self::assertSame(422, $r['status']);
    }
}
