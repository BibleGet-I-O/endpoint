<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Http\Middleware;

use BibleGet\Api\Http\Exception\ApiException;
use BibleGet\Api\Http\Exception\ForbiddenException;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Http\Exception\UnauthorizedException;
use BibleGet\Api\Http\Middleware\OidcBearerMiddleware;
use BibleGet\Api\Services\JwksProvider;
use BibleGet\Tests\Support\IsolatesEnvironment;
use BibleGet\Tests\Support\OidcTestIssuer;
use Firebase\JWT\JWT;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class OidcBearerMiddlewareTest extends TestCase
{
    use IsolatesEnvironment;

    private static OidcTestIssuer $issuer;
    private static string $jwksFile;

    public static function setUpBeforeClass(): void
    {
        self::$issuer   = new OidcTestIssuer();
        self::$jwksFile = self::$issuer->writeJwksFile();
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$jwksFile);
    }

    protected function setUp(): void
    {
        $this->isolateEnvironment(['ZITADEL_ISSUER', 'ZITADEL_AUDIENCE', 'ZITADEL_JWKS_FILE']);
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();
    }

    private function middleware(): OidcBearerMiddleware
    {
        $jwks = new JwksProvider(OidcTestIssuer::ISSUER, null, self::$jwksFile);
        return new OidcBearerMiddleware($jwks, OidcTestIssuer::ISSUER, OidcTestIssuer::AUDIENCE);
    }

    private function recordingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;
                return new Response(200);
            }
        };
    }

    private static function request(?string $authorization): ServerRequest
    {
        $headers = $authorization === null ? [] : ['Authorization' => $authorization];
        return new ServerRequest('GET', '/v3/applications', $headers);
    }

    private function assertRejected(OidcBearerMiddleware $mw, ?string $authorization, string $exception, string $why): void
    {
        try {
            $mw->process(self::request($authorization), $this->recordingHandler());
            self::fail('Expected ' . $exception . ': ' . $why);
        } catch (ApiException $e) {
            self::assertInstanceOf($exception, $e, $why . ' — got ' . $e::class . ': ' . $e->getMessage());
        }
    }

    public function testMissingOrMalformedBearerIs401(): void
    {
        $mw    = $this->middleware();
        $valid = self::$issuer->token('user-1');
        foreach (
            [
                'no header'          => null,
                'empty'              => '',
                'basic scheme'       => 'Basic dXNlcjpwYXNz',
                'bearer no token'    => 'Bearer ',
                'not a jwt'          => 'Bearer not-a-jwt',
                'two segments'       => 'Bearer abc.def',
                'garbage segments'   => 'Bearer aaa.bbb.ccc',
                'tampered signature' => 'Bearer ' . substr($valid, 0, -4) . 'AAAA',
                'api key in bearer'  => 'Bearer bibleget_test_0123456789abcdef0123456789abcdef',
            ] as $why => $header
        ) {
            $this->assertRejected($mw, $header, UnauthorizedException::class, $why);
        }
    }

    public function testBearerUnauthorizedCarriesBearerChallenge(): void
    {
        try {
            $this->middleware()->process(self::request(null), $this->recordingHandler());
            self::fail('Expected a 401');
        } catch (UnauthorizedException $e) {
            self::assertStringStartsWith('Bearer', $e->challenge());
        }
    }

    public function testWrongIssuerAudienceOrExpiredTokenIs401(): void
    {
        $mw    = $this->middleware();
        $other = new OidcTestIssuer();
        $now   = time();
        foreach (
            [
                'wrong issuer'        => self::$issuer->token('u', ['iss' => 'https://evil.example']),
                'missing issuer'      => self::$issuer->token('u', ['iss' => null]),
                'wrong audience'      => self::$issuer->token('u', ['aud' => 'another-project']),
                'missing audience'    => self::$issuer->token('u', ['aud' => null]),
                'expired'             => self::$issuer->token('u', ['exp' => $now - 3600, 'iat' => $now - 7200, 'nbf' => $now - 7200]),
                'not yet valid'       => self::$issuer->token('u', ['nbf' => $now + 3600]),
                'missing exp'         => self::$issuer->token('u', ['exp' => null]),
                'missing sub'         => self::$issuer->token('u', ['sub' => null]),
                'unknown signing key' => $other->token('u'),
                'alg none'            => self::unsignedToken(),
                'alg HS256'           => JWT::encode(
                    ['iss' => OidcTestIssuer::ISSUER, 'aud' => OidcTestIssuer::AUDIENCE, 'sub' => 'u', 'exp' => $now + 600],
                    'a-shared-secret-of-at-least-thirty-two-bytes!!',
                    'HS256',
                    OidcTestIssuer::KID
                ),
            ] as $why => $token
        ) {
            $this->assertRejected($mw, 'Bearer ' . $token, UnauthorizedException::class, $why);
        }
    }

    private static function unsignedToken(): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $hdr = $b64((string) json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => OidcTestIssuer::KID]));
        $pay = $b64((string) json_encode(['iss' => OidcTestIssuer::ISSUER, 'aud' => OidcTestIssuer::AUDIENCE, 'sub' => 'u', 'exp' => time() + 600]));
        return $hdr . '.' . $pay . '.';
    }

    public function testValidTokenSetsOidcUser(): void
    {
        $handler = $this->recordingHandler();
        $token   = self::$issuer->token('user-42', [], ['bibleget_admin']);
        $this->middleware()->process(self::request('Bearer ' . $token), $handler);

        self::assertNotNull($handler->seen);
        self::assertSame(
            ['sub' => 'user-42', 'email' => 'user-42@example.test', 'email_verified' => true, 'roles' => ['bibleget_admin']],
            $handler->seen->getAttribute('oidc_user')
        );
    }

    public function testAuthorizationFromServerParamsWhenTheHeaderIsStripped(): void
    {
        // Apache with CGI/FastCGI drops the header; .htaccess copies it into
        // HTTP_AUTHORIZATION, or REDIRECT_HTTP_AUTHORIZATION after a rewrite.
        $token = 'Bearer ' . self::$issuer->token('user-7');
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $param) {
            $handler = $this->recordingHandler();
            $request = new ServerRequest('GET', '/v3/applications', [], null, '1.1', [$param => $token]);
            $this->middleware()->process($request, $handler);
            self::assertNotNull($handler->seen, $param);
        }

        // A header that is present wins over the server parameters.
        $request = new ServerRequest('GET', '/v3/applications', ['Authorization' => 'Bearer nope'], null, '1.1', ['HTTP_AUTHORIZATION' => $token]);
        try {
            $this->middleware()->process($request, $this->recordingHandler());
            self::fail('the Authorization header should be used first');
        } catch (UnauthorizedException) {
            self::assertTrue(true);
        }
    }

    public function testExpiryWithinLeewayIsAccepted(): void
    {
        $handler = $this->recordingHandler();
        $token   = self::$issuer->token('user-1', ['exp' => time() - 30]);
        $this->middleware()->process(self::request('bearer ' . $token), $handler);
        self::assertNotNull($handler->seen);
    }

    public function testUnverifiedOrMissingEmailClaimsAreReportedFalse(): void
    {
        $handler = $this->recordingHandler();
        $token   = self::$issuer->token('user-1', ['email' => null, 'email_verified' => 'true']);
        $this->middleware()->process(self::request('Bearer ' . $token), $handler);
        self::assertNotNull($handler->seen);
        $user = $handler->seen->getAttribute('oidc_user');
        self::assertIsArray($user);
        self::assertSame('', $user['email']);
        self::assertFalse($user['email_verified']);
        self::assertSame([], $user['roles']);
    }

    public function testRequireRole(): void
    {
        $mw = $this->middleware()->requireRole('bibleget_admin');
        try {
            $mw->process(self::request('Bearer ' . self::$issuer->token('user-1')), $this->recordingHandler());
            self::fail('Expected 403 without the role');
        } catch (ForbiddenException) {
        }

        $handler = $this->recordingHandler();
        $mw->process(self::request('Bearer ' . self::$issuer->token('user-1', [], ['bibleget_admin'])), $handler);
        self::assertNotNull($handler->seen);
    }

    public function testUnconfiguredIssuerOrAudienceIs503(): void
    {
        $token = self::$issuer->token('user-1');
        try {
            ( new OidcBearerMiddleware() )->process(self::request('Bearer ' . $token), $this->recordingHandler());
            self::fail('Expected 503 with ZITADEL_ISSUER/ZITADEL_AUDIENCE unset');
        } catch (ServiceUnavailableException $e) {
            self::assertSame(503, $e->getStatus());
        }

        $_ENV['ZITADEL_ISSUER'] = OidcTestIssuer::ISSUER;
        try {
            ( new OidcBearerMiddleware() )->process(self::request('Bearer ' . $token), $this->recordingHandler());
            self::fail('Expected 503 with ZITADEL_AUDIENCE unset');
        } catch (ServiceUnavailableException $e) {
            self::assertSame(503, $e->getStatus());
        }
    }

    public function testConfiguredFromEnvironment(): void
    {
        $_ENV['ZITADEL_ISSUER']    = OidcTestIssuer::ISSUER;
        $_ENV['ZITADEL_AUDIENCE']  = OidcTestIssuer::AUDIENCE;
        $_ENV['ZITADEL_JWKS_FILE'] = self::$jwksFile;
        $handler                   = $this->recordingHandler();
        ( new OidcBearerMiddleware() )->process(self::request('Bearer ' . self::$issuer->token('user-1')), $handler);
        self::assertNotNull($handler->seen);
    }

    public function testOptionsPassesThrough(): void
    {
        $handler = $this->recordingHandler();
        ( new OidcBearerMiddleware() )->process(new ServerRequest('OPTIONS', '/v3/applications'), $handler);
        self::assertNotNull($handler->seen);
    }

    public function testJwksIsCachedAndRefetchedOnceOnUnknownKid(): void
    {
        $cache   = sys_get_temp_dir() . '/bibleget-jwks-cache-' . bin2hex(random_bytes(6)) . '.json';
        $first   = new OidcTestIssuer('kid-a');
        $second  = new OidcTestIssuer('kid-b');
        $served  = $first->jwks();
        $fetches = [];
        $now     = 1_900_000_000;
        $clock   = static function () use (&$now): int {
            return $now;
        };
        $fetcher = static function (string $url) use (&$served, &$fetches): string {
            $fetches[] = $url;
            return (string) json_encode($served);
        };

        try {
            $mw = new OidcBearerMiddleware(
                new JwksProvider(OidcTestIssuer::ISSUER, $cache, null, 3600, $fetcher, $clock),
                OidcTestIssuer::ISSUER,
                OidcTestIssuer::AUDIENCE
            );
            $mw->process(self::request('Bearer ' . $first->token('u')), $this->recordingHandler());
            $mw->process(self::request('Bearer ' . $first->token('u')), $this->recordingHandler());
            self::assertSame([OidcTestIssuer::ISSUER . '/oauth/v2/keys'], $fetches, 'second request is served from the cache');
            self::assertFileExists($cache);

            // A fresh provider reads the cache file, not the network.
            $mw2 = new OidcBearerMiddleware(
                new JwksProvider(OidcTestIssuer::ISSUER, $cache, null, 3600, $fetcher, $clock),
                OidcTestIssuer::ISSUER,
                OidcTestIssuer::AUDIENCE
            );
            $mw2->process(self::request('Bearer ' . $first->token('u')), $this->recordingHandler());
            self::assertCount(1, $fetches);

            // Key rotation: an unknown kid triggers exactly one refetch.
            $now    += 120;
            $served  = $second->jwks();
            $handler = $this->recordingHandler();
            $mw2->process(self::request('Bearer ' . $second->token('u')), $handler);
            self::assertNotNull($handler->seen);
            self::assertCount(2, $fetches);

            // Another unknown kid right after a fetch is refused without refetching,
            // so random kids cannot turn every request into a JWKS download.
            $this->assertRejected($mw2, 'Bearer ' . ( new OidcTestIssuer('kid-c') )->token('u'), UnauthorizedException::class, 'unknown kid');
            self::assertCount(2, $fetches);

            // Once the cache is past its TTL it is refetched.
            $now += 3601;
            $mw2->process(self::request('Bearer ' . $second->token('u')), $this->recordingHandler());
            self::assertCount(3, $fetches);
        } finally {
            @unlink($cache);
        }
    }
}
