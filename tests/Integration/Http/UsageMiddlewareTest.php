<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Http;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Http\Middleware\UsageMiddleware;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UsageMiddlewareTest extends DatabaseTestCase
{
    private string $credentialId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credentialId = (string) $this->getConnection()->query('SELECT gen_random_uuid()')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (!$this->status()->isSkipped()) {
            $this->getConnection()->prepare('DELETE FROM usage_daily WHERE credential_id = ?')->execute([$this->credentialId]);
        }
        parent::tearDown();
    }

    private static function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };
    }

    private function request(): ServerRequestInterface
    {
        $credential = new Credential(CredentialType::Project, $this->credentialId, 'read', 1000, null, null, null, false);
        return ( new ServerRequest('GET', '/v3/quote') )->withAttribute('credential', $credential);
    }

    public function testDayIsTheUtcDateWhateverTheSessionTimeZone(): void
    {
        $db = $this->getConnection();
        // UTC+14 and UTC-12 are 26 hours apart: at any moment one of them is
        // on a different date than UTC.
        foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $zone) {
            // Each zone checks only the row it wrote.
            $db->prepare('DELETE FROM usage_daily WHERE credential_id = ?')->execute([$this->credentialId]);
            $db->exec("SET TIME ZONE '{$zone}'");
            $before = gmdate('Y-m-d');
            ( new UsageMiddleware('quote', $db) )->process($this->request(), self::handler());
            $after = gmdate('Y-m-d');

            $stmt = $db->prepare('SELECT day::text FROM usage_daily WHERE credential_id = ? ORDER BY day DESC LIMIT 1');
            $stmt->execute([$this->credentialId]);
            self::assertContains($stmt->fetchColumn(), array_unique([$before, $after]), $zone);
        }
        $db->exec('SET TIME ZONE DEFAULT');
    }

    public function testDatabaseFailureLeavesTheResponseUntouched(): void
    {
        // A PDO that was never connected: every call on it throws.
        $broken   = new class extends \PDO {
            public function __construct()
            {
            }
        };
        $response = ( new UsageMiddleware('quote', $broken) )->process($this->request(), self::handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
    }
}
