<?php

declare(strict_types=1);

namespace BibleGet\Tests\Server;

use PHPUnit\Framework\Attributes\Group;

/**
 * /_ops/migrate through the Router: gated by X-Deploy-Token, never by an API key or a Zitadel token. The
 * migration run itself is covered by tests/Integration/Handlers/Ops/MigrateHandlerTest.
 */
#[Group('slow')]
class OpsHttpTest extends ServerTestCase
{
    private const TOKEN = 'ops-http-test-deploy-token-0123456789abcdef';

    protected static function serverPort(): int
    {
        return self::basePort() + 5;
    }

    protected static function serverEnv(): array
    {
        return ['APP_ENV' => 'staging', 'DEPLOY_TOKEN' => self::TOKEN, 'REQUIRE_CREDENTIALS' => 'true'];
    }

    public function testWithoutTheTokenTheRouteAnswers401(): void
    {
        self::assertSame(401, self::httpPost('/_ops/migrate')['status']);
        self::assertSame(401, self::httpGet('/_ops/migrate/status', ['X-Deploy-Token' => 'wrong'])['status']);
    }

    public function testAnApiKeyIsNotAToken(): void
    {
        self::assertSame(401, self::httpGet('/_ops/migrate/status', ['X-Api-Key' => self::TOKEN])['status']);
    }

    public function testWithTheTokenStatusRuns(): void
    {
        $response = self::httpGet('/_ops/migrate/status', ['X-Deploy-Token' => self::TOKEN]);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('BibleGet\\Api\\Migrations', $response['body']);
    }

    public function testUnknownOpsPathsAreNotFound(): void
    {
        self::assertSame(404, self::httpGet('/_ops/other', ['X-Deploy-Token' => self::TOKEN])['status']);
    }
}
