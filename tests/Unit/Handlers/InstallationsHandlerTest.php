<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Handlers\InstallationsHandler;
use BibleGet\Api\Http\Exception\UnauthorizedException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Credential checks of POST /installations and PATCH /installations/self that
 * need no database: a `_test_` credential is refused in production before
 * any lookup (the database is made unreachable, so a lookup would be a 500).
 */
class InstallationsHandlerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'DB_HOST'] as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
        }
        $_ENV['APP_ENV'] = 'production';
        $_ENV['DB_HOST'] = '';
        Connection::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        Connection::reset();
    }

    public function testTestClientKeyCannotRegisterInProduction(): void
    {
        $request = new ServerRequest('POST', '/v3/installations', [
            'X-Api-Key'    => 'bgclient_test_' . str_repeat('a', 32),
            'Content-Type' => 'application/json',
        ], '{"install_id":"5a1c0de0-0000-4000-8000-0000000000a1","plugin_version":"1"}');

        $this->expectException(UnauthorizedException::class);
        ( new InstallationsHandler(new Psr17Factory()) )->handle($request);
    }

    public function testTestInstallationTokenCannotPatchInProduction(): void
    {
        $request = new ServerRequest('PATCH', '/v3/installations/self', [
            'X-Api-Key'    => 'bginst_test_' . str_repeat('a', 32),
            'Content-Type' => 'application/json',
        ], '{"plugin_version":"2"}');

        $this->expectException(UnauthorizedException::class);
        ( new InstallationsHandler(new Psr17Factory(), ['self']) )->handle($request);
    }
}
