<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Enum\StatusCode;
use BibleGet\Api\Http\Exception\BearerUnauthorizedException;
use BibleGet\Api\Http\Exception\ConflictException;
use BibleGet\Api\Http\Exception\ForbiddenException;
use BibleGet\Api\Http\Exception\MethodNotAllowedException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Repositories\ApiKeyRepository;
use BibleGet\Api\Repositories\ApplicationRepository;
use BibleGet\Api\Repositories\Cast;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The developer's own applications (projects) and their API keys, behind
 * OidcBearerMiddleware (the `oidc_user` attribute):
 *
 *   GET|POST            /applications
 *   GET|PATCH|DELETE    /applications/{id}
 *   POST                /applications/{id}/resubmit
 *   GET|POST            /applications/{id}/keys
 *   DELETE              /applications/{id}/keys/{keyId}
 *   POST                /applications/{id}/keys/{keyId}/rotate
 *
 * Every route below /applications/{id} first loads the application through
 * ApplicationRepository::findForOwner, and every key route checks that the key
 * belongs to that application, so another user's application or key is a 404
 * (its existence is never revealed). Creating an application needs a verified
 * email; keys are minted only for approved applications, always with scope
 * `read`, and their plaintext appears only in the mint/rotate response.
 */
class ApplicationsHandler extends AbstractHandler
{
    private const UUID_PATTERN     = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';
    private const PUBLIC_FIELDS    = [
        'id',
        'name',
        'description',
        'website',
        'status',
        'review_notes',
        'reviewed_at',
        'is_active',
        'created_at',
        'updated_at',
    ];
    private const DEFAULT_KEY_NAME = 'Default';

    /** @var array<string, list<string>> */
    private const ROUTE_METHODS = [
        'application' => ['GET', 'PATCH', 'DELETE'],
        'resubmit'    => ['POST'],
        'keys'        => ['GET', 'POST'],
        'key'         => ['DELETE'],
        'rotate'      => ['POST'],
    ];

    private ?ApplicationRepository $apps = null;
    private ?ApiKeyRepository $keys      = null;

    /** @param string[] $requestPathParams */
    public function __construct(ResponseFactoryInterface $responseFactory, array $requestPathParams = [])
    {
        parent::__construct($responseFactory, $requestPathParams);
        $this->allowedRequestMethods      = [
            RequestMethod::GET,
            RequestMethod::POST,
            RequestMethod::PATCH,
            RequestMethod::DELETE,
            RequestMethod::OPTIONS,
        ];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response(200, [], null, $request->getProtocolVersion(), 'OK');
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);
        $this->validateRequestContentType($request);

        $user = $request->getAttribute('oidc_user');
        if (!is_array($user) || !is_string($user['sub'] ?? null)) {
            throw new BearerUnauthorizedException();
        }

        [$route, $appId, $keyId] = $this->resolveRoute();
        $method                  = $request->getMethod();
        $owner                   = $user['sub'];

        if ($route === 'collection') {
            return match ($method) {
                'GET'   => $this->listApplications($request, $owner),
                'POST'  => $this->createApplication($request, $owner, ( $user['email_verified'] ?? false ) === true),
                default => throw new MethodNotAllowedException(),
            };
        }

        self::requireMethod($method, self::ROUTE_METHODS[$route]);
        $app = $this->ownedApplication($appId, $owner);
        return match ($route . ' ' . $method) {
            'application GET'    => $this->getApplication($request, $app),
            'application PATCH'  => $this->updateApplication($request, $app, $owner),
            'application DELETE' => $this->deleteApplication($request, $app, $owner),
            'resubmit POST'      => $this->resubmitApplication($request, $app, $owner),
            'keys GET'           => $this->listKeys($request, $app),
            'keys POST'          => $this->mintKey($request, $app),
            'key DELETE'         => $this->revokeKey($request, $app, $keyId),
            default              => $this->rotateKey($request, $app, $keyId),
        };
    }

    /**
     * The route name with the application and key ids it names; a path of any
     * other shape, or with an id that is not a UUID, is a 404.
     *
     * @return array{string, string, string}
     */
    private function resolveRoute(): array
    {
        $p = array_values($this->requestPathParams);
        $n = count($p);
        if ($n === 0) {
            return ['collection', '', ''];
        }
        $route = match (true) {
            $n === 1                                                => 'application',
            $n === 2 && $p[1] === 'resubmit'                        => 'resubmit',
            $n === 2 && $p[1] === 'keys'                            => 'keys',
            $n === 3 && $p[1] === 'keys'                            => 'key',
            $n === 4 && $p[1] === 'keys' && $p[3] === 'rotate'      => 'rotate',
            default                                                 => null,
        };
        $keyId = $n >= 3 ? $p[2] : '';
        if ($route === null || !self::isUuid($p[0]) || ( $n >= 3 && !self::isUuid($keyId) )) {
            throw new NotFoundException();
        }
        return [$route, strtolower($p[0]), strtolower($keyId)];
    }

    /** @param list<string> $allowed */
    private static function requireMethod(string $method, array $allowed): void
    {
        if (!in_array($method, $allowed, true)) {
            throw new MethodNotAllowedException();
        }
    }

    private function listApplications(ServerRequestInterface $request, string $owner): ResponseInterface
    {
        $apps = array_map(self::publicApplication(...), $this->apps()->listForOwner($owner));
        return $this->json($request, ['applications' => $apps, 'total' => count($apps)]);
    }

    private function createApplication(ServerRequestInterface $request, string $owner, bool $emailVerified): ResponseInterface
    {
        if (!$emailVerified) {
            throw new ForbiddenException('Verify your email address before creating an application.');
        }
        $params = $this->getJsonBodyParams($request);
        $name   = self::validName($params['name'] ?? null, 'name');
        $desc   = self::validDescription($params['description'] ?? null);
        $site   = self::validWebsite($params['website'] ?? null);

        $id  = $this->apps()->create($owner, $name, $desc, $site);
        $app = $this->apps()->findForOwner($id, $owner) ?? [];
        return $this->json($request, ['application' => self::publicApplication($app)], StatusCode::CREATED);
    }

    /** @param array<string, mixed> $app */
    private function getApplication(ServerRequestInterface $request, array $app): ResponseInterface
    {
        return $this->json($request, [
            'application' => self::publicApplication($app),
            'keys'        => $this->keys()->listForApplication(self::idOf($app)),
        ]);
    }

    /** @param array<string, mixed> $app */
    private function updateApplication(ServerRequestInterface $request, array $app, string $owner): ResponseInterface
    {
        $params = $this->getJsonBodyParams($request);
        $fields = [];
        if (array_key_exists('name', $params)) {
            $fields['name'] = self::validName($params['name'], 'name');
        }
        if (array_key_exists('description', $params)) {
            $fields['description'] = self::validDescription($params['description']);
        }
        if (array_key_exists('website', $params)) {
            $fields['website'] = self::validWebsite($params['website']);
        }
        if ($fields === []) {
            throw new ValidationException('Send at least one of name, description, website.');
        }
        $id = self::idOf($app);
        $this->apps()->update($id, $owner, $fields);
        return $this->json($request, ['application' => self::publicApplication($this->ownedApplication($id, $owner))]);
    }

    /** @param array<string, mixed> $app */
    private function deleteApplication(ServerRequestInterface $request, array $app, string $owner): ResponseInterface
    {
        $this->apps()->delete(self::idOf($app), $owner);
        return $this->noContent($request);
    }

    /** @param array<string, mixed> $app */
    private function resubmitApplication(ServerRequestInterface $request, array $app, string $owner): ResponseInterface
    {
        $id = self::idOf($app);
        if (( $app['status'] ?? null ) !== 'rejected' || !$this->apps()->resubmit($id, $owner)) {
            throw new ConflictException('Only a rejected application can be resubmitted.');
        }
        return $this->json($request, ['application' => self::publicApplication($this->ownedApplication($id, $owner))]);
    }

    /** @param array<string, mixed> $app */
    private function listKeys(ServerRequestInterface $request, array $app): ResponseInterface
    {
        $keys = $this->keys()->listForApplication(self::idOf($app));
        return $this->json($request, ['keys' => $keys, 'total' => count($keys)]);
    }

    /** @param array<string, mixed> $app */
    private function mintKey(ServerRequestInterface $request, array $app): ResponseInterface
    {
        self::requireApproved($app);
        $params = $this->getJsonBodyParams($request);
        $name   = array_key_exists('name', $params) ? self::validName($params['name'], 'name') : self::DEFAULT_KEY_NAME;

        $appId  = self::idOf($app);
        $minted = $this->keys()->mint($appId, $name, 'read');
        return $this->keyResponse($request, $appId, $minted, StatusCode::CREATED);
    }

    /** @param array<string, mixed> $app */
    private function revokeKey(ServerRequestInterface $request, array $app, string $keyId): ResponseInterface
    {
        $this->ownedKey(self::idOf($app), $keyId);
        $this->keys()->revoke($keyId);
        return $this->noContent($request);
    }

    /** @param array<string, mixed> $app */
    private function rotateKey(ServerRequestInterface $request, array $app, string $keyId): ResponseInterface
    {
        $appId = self::idOf($app);
        $this->ownedKey($appId, $keyId);
        self::requireApproved($app);
        $rotated = $this->keys()->rotate($keyId);
        if ($rotated === null) {
            throw new NotFoundException('API key not found, or already revoked.');
        }
        return $this->keyResponse($request, $appId, $rotated, StatusCode::OK);
    }

    /** @param array{plain: string, id: string} $minted */
    private function keyResponse(ServerRequestInterface $request, string $appId, array $minted, StatusCode $status): ResponseInterface
    {
        return $this->json($request, [
            'message' => 'Save this key now: it will not be shown again.',
            'key'     => $minted['plain'],
            'record'  => $this->ownedKey($appId, $minted['id']),
        ], $status);
    }

    /** @return array<string, mixed> */
    private function ownedApplication(string $id, string $owner): array
    {
        $app = $this->apps()->findForOwner($id, $owner);
        if ($app === null) {
            throw new NotFoundException('Application not found.');
        }
        return $app;
    }

    /**
     * The key's listing row, if it belongs to the (already owner-checked) application.
     *
     * @return array<string, mixed>
     */
    private function ownedKey(string $appId, string $keyId): array
    {
        foreach ($this->keys()->listForApplication($appId) as $key) {
            if (( $key['id'] ?? null ) === $keyId) {
                return $key;
            }
        }
        throw new NotFoundException('API key not found.');
    }

    /** @param array<string, mixed> $app */
    private static function requireApproved(array $app): void
    {
        if (( $app['status'] ?? null ) !== 'approved' || !Cast::bool($app['is_active'] ?? false)) {
            $status = is_string($app['status'] ?? null) ? $app['status'] : 'unknown';
            throw new ConflictException("API keys can only be issued for approved applications; this one is {$status}.");
        }
    }

    private static function validName(mixed $v, string $field): string
    {
        $v = is_string($v) ? trim($v) : null;
        if ($v === null || $v === '' || mb_strlen($v, 'UTF-8') > 100 || !self::isCleanText($v)) {
            throw new ValidationException("{$field} must be text of 1 to 100 characters, without control characters.");
        }
        return $v;
    }

    private static function validDescription(mixed $v): ?string
    {
        if ($v === null || ( is_string($v) && trim($v) === '' )) {
            return null;
        }
        if (!is_string($v) || mb_strlen($v, 'UTF-8') > 1000 || !self::isCleanText($v)) {
            throw new ValidationException('description must be text of at most 1000 characters, without control characters.');
        }
        return trim($v);
    }

    private static function validWebsite(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (
            !is_string($v) || strlen($v) > 255 || !self::isCleanText($v)
            || preg_match('#^https?://#i', $v) !== 1 || filter_var($v, FILTER_VALIDATE_URL) === false
        ) {
            throw new ValidationException('website must be an http(s) URL of at most 255 characters.');
        }
        return $v;
    }

    private static function isUuid(string $v): bool
    {
        return preg_match(self::UUID_PATTERN, $v) === 1;
    }

    /** @param array<string, mixed> $app */
    private static function idOf(array $app): string
    {
        return is_string($app['id'] ?? null) ? $app['id'] : '';
    }

    /**
     * @param array<string, mixed> $app
     * @return array<string, mixed>
     */
    private static function publicApplication(array $app): array
    {
        return array_intersect_key($app, array_flip(self::PUBLIC_FIELDS));
    }

    private function json(ServerRequestInterface $request, mixed $data, StatusCode $status = StatusCode::OK): ResponseInterface
    {
        $response = $this->initResponse($request, 'application/json')
            ->withStatus($status->value, $status->reason())
            ->withHeader('Cache-Control', 'no-store');
        return $this->jsonResponse($response, $data);
    }

    private function noContent(ServerRequestInterface $request): ResponseInterface
    {
        return $this->initResponse($request, 'application/json')
            ->withStatus(StatusCode::NO_CONTENT->value, StatusCode::NO_CONTENT->reason())
            ->withHeader('Cache-Control', 'no-store')
            ->withoutHeader('Content-Type');
    }

    private function apps(): ApplicationRepository
    {
        return $this->apps ??= new ApplicationRepository(Connection::getConnection());
    }

    private function keys(): ApiKeyRepository
    {
        return $this->keys ??= new ApiKeyRepository(Connection::getConnection());
    }
}
