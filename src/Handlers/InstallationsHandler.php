<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Enum\RequestContentType;
use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Enum\StatusCode;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\TooManyRequestsException;
use BibleGet\Api\Http\Exception\UnauthorizedException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Middleware\RateLimitMiddleware;
use BibleGet\Api\Repositories\ClientKeyRepository;
use BibleGet\Api\Repositories\InstallationRepository;
use BibleGet\Api\Services\RateLimiter;
use BibleGet\Api\Services\SiteUrl;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /installations (client key registers an installation) and
 * PATCH /installations/self (installation token updates its own record;
 * counted against the installation's hourly rate limit).
 *
 * Not behind CredentialMiddleware: each method validates its own credential
 * from the X-Api-Key header only (in production, `_test_` credentials are
 * refused before any lookup).
 */
class InstallationsHandler extends AbstractHandler
{
    /**
     * A random (version 4, RFC 9562 variant) UUID: install_id acts as a
     * secret, since presenting it with the client key rotates the token.
     */
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';

    /** @param string[] $requestPathParams */
    public function __construct(ResponseFactoryInterface $responseFactory, array $requestPathParams = [])
    {
        parent::__construct($responseFactory, $requestPathParams);
        $this->allowedRequestMethods      = [RequestMethod::POST, RequestMethod::PATCH, RequestMethod::OPTIONS];
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

        if ($request->getMethod() === 'POST') {
            if ($this->requestPathParams !== []) {
                throw new NotFoundException();
            }
            return $this->register($request);
        }

        if ($this->requestPathParams !== ['self']) {
            throw new NotFoundException();
        }
        return $this->updateSelf($request);
    }

    private function register(ServerRequestInterface $request): ResponseInterface
    {
        $presented = trim($request->getHeaderLine('X-Api-Key'));
        $client    = CredentialFactory::typeOf($presented) === CredentialType::Client
            ? ( new ClientKeyRepository(Connection::getConnection()) )->findActiveByHash(CredentialFactory::hash($presented))
            : null;
        if ($client === null) {
            throw new UnauthorizedException(
                'A client key is required in the X-Api-Key header, and it is unknown, revoked or inactive.'
            );
        }

        $params    = $this->getJsonBodyParams($request);
        $installId = $params['install_id'] ?? null;
        if (!is_string($installId) || preg_match(self::UUID_V4_PATTERN, $installId) !== 1) {
            throw new ValidationException('install_id must be a random (version 4) UUID.');
        }
        $version = $this->validVersion($params['plugin_version'] ?? null);
        if ($version === null) {
            throw new ValidationException('plugin_version is required (at most 50 characters).');
        }
        $siteUrl = $this->siteUrlFor($client['plugin'], $params);

        $limit  = $client['max_registrations_per_hour'];
        $result = ( new RateLimiter() )->hit('registrations:' . $client['id'], $limit);
        if (!$result['allowed']) {
            throw new TooManyRequestsException(
                "Registration limit exceeded: at most {$limit} registrations per hour for this client key.",
                max(1, $result['reset'] - time()),
                null,
                RateLimitMiddleware::headers($limit, $result)
            );
        }

        $reg      = ( new InstallationRepository(Connection::getConnection()) )->register($client['id'], strtolower($installId), $version, $siteUrl);
        $status   = $reg['created'] ? StatusCode::CREATED : StatusCode::OK;
        $response = $this->initResponse($request, 'application/json')
            ->withStatus($status->value, $status->reason())
            ->withHeader('Cache-Control', 'no-store');
        return $this->jsonResponse($response, ['token' => $reg['token'], 'installation_id' => $reg['installation_id']]);
    }

    private function updateSelf(ServerRequestInterface $request): ResponseInterface
    {
        $presented  = trim($request->getHeaderLine('X-Api-Key'));
        $credential = CredentialFactory::typeOf($presented) === CredentialType::Installation
            ? ( new InstallationRepository(Connection::getConnection()) )->findActiveByHash(CredentialFactory::hash($presented))
            : null;
        if ($credential === null) {
            throw new UnauthorizedException(
                'An installation token is required in the X-Api-Key header, and it is unknown or revoked.'
            );
        }

        // Counted against the installation's own hourly limit, like its data requests.
        $rateHeaders = ( new RateLimitMiddleware() )->enforce($credential);

        $params = $this->getJsonBodyParams($request);
        $fields = [];
        if (array_key_exists('plugin_version', $params)) {
            $version = $this->validVersion($params['plugin_version']);
            if ($version === null) {
                throw new ValidationException('plugin_version must be a non-empty string of at most 50 characters.');
            }
            $fields['plugin_version'] = $version;
        }
        if (array_key_exists('site_url', $params)) {
            $fields['site_url'] = $this->siteUrlFor((string) $credential->plugin, $params);
        }
        if (array_key_exists('contact_email', $params)) {
            $email = $params['contact_email'];
            if ($email === '' || $email === null) {
                $fields['contact_email'] = null;
            } elseif (is_string($email) && strlen($email) <= 255 && self::isCleanText($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $fields['contact_email'] = $email;
            } else {
                throw new ValidationException('contact_email must be a valid email address, or "" to remove it.');
            }
        }

        ( new InstallationRepository(Connection::getConnection()) )->updateSelf($credential->id, $fields);

        $response = $this->initResponse($request, 'application/json')
            ->withStatus(StatusCode::NO_CONTENT->value, StatusCode::NO_CONTENT->reason());
        foreach ($rateHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response->withoutHeader('Content-Type');
    }

    private function validVersion(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[0-9A-Za-z.+_-]{1,50}\z/', $v) === 1 ? $v : null;
    }

    /** The plugins that run on a site and send its address; MediaWiki must (a wiki is counted by its address). */
    private const SITE_PLUGINS          = ['wordpress', 'mediawiki'];
    private const SITE_REQUIRED_PLUGINS = ['mediawiki'];

    /**
     * The body's site_url, as its origin (src/Services/SiteUrl.php: a public https address only). Absent, null or
     * "" means none, from a plugin that does not have to send one; a MediaWiki installation must, on registering
     * and afterwards.
     *
     * @param array<string, mixed> $params
     */
    private function siteUrlFor(string $plugin, array $params): ?string
    {
        $v = $params['site_url'] ?? null;
        if ($v === null || $v === '') {
            if (in_array($plugin, self::SITE_REQUIRED_PLUGINS, true)) {
                throw new ValidationException(
                    "site_url is required from {$plugin} installations: the site's public https address, which counts it as an installation."
                );
            }
            return null;
        }
        if (!in_array($plugin, self::SITE_PLUGINS, true)) {
            throw new ValidationException('site_url is only accepted from WordPress and MediaWiki installations.');
        }
        return SiteUrl::origin($v);
    }
}
