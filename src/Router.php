<?php

declare(strict_types=1);

namespace BibleGet\Api;

use BibleGet\Api\Handlers\Admin\ApplicationAdminHandler;
use BibleGet\Api\Handlers\Admin\ClientKeyAdminHandler;
use BibleGet\Api\Handlers\Admin\InstallationAdminHandler;
use BibleGet\Api\Handlers\Admin\StatsHandler;
use BibleGet\Api\Handlers\Admin\VersionAdminHandler;
use BibleGet\Api\Handlers\ApplicationsHandler;
use BibleGet\Api\Handlers\Ops\MigrateHandler;
use BibleGet\Api\Handlers\InstallationsHandler;
use BibleGet\Api\Handlers\QuoteHandler;
use BibleGet\Api\Handlers\MetadataHandler;
use BibleGet\Api\Handlers\HybridSearchHandler;
use BibleGet\Api\Handlers\KeywordSearchHandler;
use BibleGet\Api\Handlers\SearchHandler;
use BibleGet\Api\Handlers\SemanticSearchHandler;
use BibleGet\Api\Handlers\SimilarSearchHandler;
use BibleGet\Api\Http\Enum\StatusCode;
use BibleGet\Api\Http\Exception\ServiceUnavailableException;
use BibleGet\Api\Http\Middleware\CredentialMiddleware;
use BibleGet\Api\Http\Middleware\DeployTokenMiddleware;
use BibleGet\Api\Http\Middleware\ErrorHandlingMiddleware;
use BibleGet\Api\Http\Middleware\LoggingMiddleware;
use BibleGet\Api\Http\Middleware\OidcBearerMiddleware;
use BibleGet\Api\Http\Middleware\RateLimitMiddleware;
use BibleGet\Api\Http\Middleware\UsageMiddleware;
use BibleGet\Api\Http\Server\MiddlewarePipeline;
use BibleGet\Api\Util\AppVersion;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Router
{
    public const ADMIN_ROLE = 'bibleget_admin';

    public static string $apiBase = '/';
    private RequestHandlerInterface $handler;
    private Psr17Factory $psr17Factory;
    private ServerRequestInterface $request;
    private string $requestId;
    private ResponseInterface $response;
    private static bool $debug;

    public function __construct()
    {
        if (!isset(self::$debug)) {
            self::$debug = self::isLocalhost();
        }

        self::resolveBasePath();

        $this->psr17Factory = new Psr17Factory();
        $this->request      = $this->retrieveRequest();

        try {
            $this->requestId = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $this->requestId = uniqid('bg');
        }

        $this->request = $this->request->withAttribute('request_id', $this->requestId);
    }

    /**
     * Route the incoming request and emit the response.
     *
     * @return never
     */
    public function route(): never
    {
        $path             = $this->request->getUri()->getPath();
        $pathParams       = str_starts_with($path, self::$apiBase)
            ? substr($path, strlen(self::$apiBase))
            : $path;
        $pathParams       = rtrim($pathParams, '/');
        $requestPathParts = array_values(array_filter(explode('/', $pathParams)));
        $route            = array_shift($requestPathParts) ?? '';

        $this->handler = $this->createHandler($route, $requestPathParts);

        $pipeline = new MiddlewarePipeline($this->handler);
        $pipeline->pipe(new ErrorHandlingMiddleware($this->psr17Factory, self::$debug));
        $pipeline->pipe(new LoggingMiddleware(self::$debug));

        if ($route === '_ops') {
            $pipeline->pipe(new DeployTokenMiddleware());
        }

        $oidc = self::oidcMiddleware($route);
        if ($oidc !== null) {
            $pipeline->pipe($oidc);
        }

        $usageRoute = self::dataRouteName($route, $requestPathParts);
        if ($usageRoute !== null) {
            $pipeline->pipe(new CredentialMiddleware());
            $pipeline->pipe(new RateLimitMiddleware());
            $pipeline->pipe(new UsageMiddleware($usageRoute));
        }

        $this->response = $pipeline->handle($this->request)
            ->withHeader('X-Request-Id', $this->requestId)
            ->withHeader('X-BibleGet-Version', AppVersion::current());
        $this->emitResponse();
    }

    /**
     * Resolve the API base path from the environment.
     *
     * In production, API_BASE_PATH must be set in the environment.
     * In localhost/development, it defaults to '/'.
     */
    private static function resolveBasePath(): void
    {
        if (
            false === self::isLocalhost()
            && (
                false === isset($_ENV['API_BASE_PATH'])
                || false === is_string($_ENV['API_BASE_PATH'])
                || empty($_ENV['API_BASE_PATH'])
            )
        ) {
            throw new ServiceUnavailableException('The API_BASE_PATH environment variable must be set in production environments.');
        }

        if (isset($_ENV['API_BASE_PATH']) && is_string($_ENV['API_BASE_PATH']) && !empty($_ENV['API_BASE_PATH'])) {
            $apiBasePath = trim($_ENV['API_BASE_PATH']);
        } else {
            $apiBasePath = '/';
        }

        // Normalize: ensure leading slash, strip duplicates
        $apiBasePath = '/' . trim($apiBasePath, '/');

        // Ensure trailing slash (but avoid double-slash for root)
        if ($apiBasePath !== '/' && substr($apiBasePath, -1) !== '/') {
            $apiBasePath .= '/';
        }

        self::$apiBase = $apiBasePath;
    }

    /**
     * @param array<string, mixed>|null $serverParams  Override for testing; defaults to $_SERVER.
     */
    public static function isLocalhost(?array $serverParams = null): bool
    {
        $server             = $serverParams ?? $_SERVER;
        $rawAddr            = $server['SERVER_ADDR'] ?? '';
        $rawRemote          = $server['REMOTE_ADDR'] ?? '';
        $rawName            = $server['SERVER_NAME'] ?? '';
        $serverAddress      = is_string($rawAddr) ? $rawAddr : '';
        $remoteAddress      = is_string($rawRemote) ? $rawRemote : '';
        $serverName         = is_string($rawName) ? $rawName : '';
        $localhostAddresses = ['127.0.0.1', '::1', '0.0.0.0'];
        $localhostNames     = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        return in_array($serverAddress, $localhostAddresses)
            || in_array($remoteAddress, $localhostAddresses)
            || in_array($serverName, $localhostNames);
    }

    /**
     * The usage_daily route name of a data route (the first path segment, plus
     * the second for search), or null for a route that needs no credential
     * (including unknown routes, which 404).
     * The empty route is the legacy alias of /quote.
     *
     * @param list<string> $pathParts the path segments after the first
     */
    public static function dataRouteName(string $route, array $pathParts): ?string
    {
        return match ($route) {
            '', 'quote' => 'quote',
            'metadata'  => 'metadata',
            'search'    => self::searchRouteName($pathParts[0] ?? ''),
            default     => null,
        };
    }

    /** Unknown search sub-routes are 404s, not data routes, so no free-form text reaches usage_daily. */
    private static function searchRouteName(string $subRoute): ?string
    {
        if ($subRoute === '') {
            return 'search';
        }
        return in_array($subRoute, ['keyword', 'semantic', 'hybrid', 'similar'], true) ? 'search/' . $subRoute : null;
    }

    /**
     * Bearer-token authentication for the developer routes (any signed-in
     * user) and the admin routes (role bibleget_admin: every admin path,
     * including unknown ones, needs the role before it can 404); null for
     * every other route.
     */
    private static function oidcMiddleware(string $route): ?OidcBearerMiddleware
    {
        return match ($route) {
            'applications' => new OidcBearerMiddleware(),
            'admin'        => ( new OidcBearerMiddleware() )->requireRole(self::ADMIN_ROLE),
            default        => null,
        };
    }

    /**
     * The handler for a route (the first path segment) and the segments after it.
     *
     * @param list<string> $pathParts
     */
    private function createHandler(string $route, array $pathParts): RequestHandlerInterface
    {
        return match ($route) {
            '', 'quote'    => new QuoteHandler($this->psr17Factory, $pathParts),
            'metadata'     => new MetadataHandler($this->psr17Factory, $pathParts),
            // No CredentialMiddleware: the handler validates the client key or installation token itself.
            'installations' => new InstallationsHandler($this->psr17Factory, $pathParts),
            // Developer routes: a Zitadel access token in Authorization: Bearer, never an X-Api-Key credential.
            'applications' => new ApplicationsHandler($this->psr17Factory, $pathParts),
            // Admin routes: a Zitadel access token with the bibleget_admin role (see the pipeline in route()).
            'admin'        => $this->createAdminHandler($pathParts),
            // Deploy-time operations: X-Deploy-Token only (see the pipeline in route()), no credential or OIDC.
            '_ops'         => match ($pathParts) {
                ['migrate']           => new MigrateHandler(false),
                ['migrate', 'status'] => new MigrateHandler(true),
                default               => $this->createNotFoundHandler(),
            },
            'search'       => $this->createSearchHandler($pathParts),
            default        => $this->createNotFoundHandler(),
        };
    }

    /**
     * The handler for /search/{kind}/...; /search alone is the backward-compatible keyword search.
     *
     * @param list<string> $pathParts the segments after /search
     */
    private function createSearchHandler(array $pathParts): RequestHandlerInterface
    {
        $subPathParts = array_slice($pathParts, 1);
        return match ($pathParts[0] ?? '') {
            'keyword'  => new KeywordSearchHandler($this->psr17Factory, $subPathParts),
            'semantic' => new SemanticSearchHandler($this->psr17Factory, $subPathParts),
            'hybrid'   => new HybridSearchHandler($this->psr17Factory, $subPathParts),
            'similar'  => new SimilarSearchHandler($this->psr17Factory, $subPathParts),
            // Backward-compatible alias: /v3/search → SearchHandler (delegates to KeywordSearchHandler)
            ''         => new SearchHandler($this->psr17Factory, $pathParts),
            default    => $this->createNotFoundHandler(),
        };
    }

    /**
     * The handler for /admin/{resource}/...; it receives the path segments after /admin.
     *
     * @param list<string> $pathParts
     */
    private function createAdminHandler(array $pathParts): RequestHandlerInterface
    {
        return match ($pathParts[0] ?? '') {
            'applications', 'api-keys' => new ApplicationAdminHandler($this->psr17Factory, $pathParts),
            'client-keys'              => new ClientKeyAdminHandler($this->psr17Factory, $pathParts),
            'installations'            => new InstallationAdminHandler($this->psr17Factory, $pathParts),
            'stats'                    => new StatsHandler($this->psr17Factory, $pathParts),
            'versions'                 => new VersionAdminHandler($this->psr17Factory, $pathParts),
            default                    => $this->createNotFoundHandler(),
        };
    }

    private function createNotFoundHandler(): RequestHandlerInterface
    {
        $protocolVersion = $this->request->getProtocolVersion();
        $factory         = $this->psr17Factory;
        return new class ($protocolVersion, $factory) implements RequestHandlerInterface {
            public function __construct(
                private readonly string $protocolVersion,
                private readonly Psr17Factory $psr17Factory
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->psr17Factory->createResponse(StatusCode::NOT_FOUND->value, StatusCode::NOT_FOUND->reason())
                    ->withProtocolVersion($this->protocolVersion);
            }
        };
    }

    private function retrieveRequest(): ServerRequestInterface
    {
        $creator = new ServerRequestCreator(
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory
        );
        return $creator->fromGlobals();
    }

    private function emitResponse(): never
    {
        $sapiEmitter = new SapiEmitter();
        $sapiEmitter->emit($this->response);
        exit;
    }
}
