<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Admin;

use BibleGet\Api\Http\Exception\ConflictException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Repositories\ClientKeyRepository;
use BibleGet\Api\Repositories\InstallationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Plugin installations (role bibleget_admin):
 *
 *   GET   /admin/installations?plugin=&version=&active_since=&registered_from=&registered_to=&limit=
 *   POST  /admin/installations/{id}/revoke
 *   POST  /admin/installations/revoke-batch   {client_key_id, from, to} → {revoked: n}
 *   PATCH /admin/installations/{id}           {rate_limit_per_hour}
 *
 * Time windows are [from, to): ISO-8601, UTC when no offset is given.
 * revoke-batch is the response to a registration burst on a public client key
 * (see the design's accepted risk): it revokes the installations that key
 * first registered in the window, which may be at most 31 days long.
 */
class InstallationAdminHandler extends AbstractAdminHandler
{
    private const BATCH_MAX_DAYS = 31;
    private const LIST_DEFAULT   = 200;
    private const LIST_MAX       = 1000;

    protected function dispatch(ServerRequestInterface $request, string $method, array $path, string $admin): ResponseInterface
    {
        $n = count($path);
        if ($n === 0) {
            self::requireMethod($method, ['GET']);
            return $this->listInstallations($request);
        }
        if ($n === 1 && $path[0] === 'revoke-batch') {
            self::requireMethod($method, ['POST']);
            return $this->revokeBatch($request);
        }
        if ($n > 2 || ( $n === 2 && $path[1] !== 'revoke' )) {
            throw new NotFoundException();
        }
        $id = self::pathId($path[0]);
        if ($n === 2) {
            self::requireMethod($method, ['POST']);
            return $this->revokeOne($request, $id);
        }
        self::requireMethod($method, ['PATCH']);
        return $this->setLimit($request, $id);
    }

    private function listInstallations(ServerRequestInterface $request): ResponseInterface
    {
        $q       = $request->getQueryParams();
        $filters = [];
        if (isset($q['plugin'])) {
            if (!is_string($q['plugin']) || !in_array($q['plugin'], ClientKeyAdminHandler::PLUGINS, true)) {
                throw new ValidationException('plugin must be one of ' . implode(', ', ClientKeyAdminHandler::PLUGINS) . '.');
            }
            $filters['plugin'] = $q['plugin'];
        }
        if (isset($q['version'])) {
            if (!is_string($q['version']) || preg_match(ClientKeyAdminHandler::VERSION_PATTERN, $q['version']) !== 1) {
                throw new ValidationException('version must be 1 to 50 letters, digits or . + _ -');
            }
            $filters['version'] = $q['version'];
        }
        foreach (['active_since', 'registered_from', 'registered_to'] as $field) {
            if (isset($q[$field])) {
                $filters[$field] = self::isoDate($q[$field], $field);
            }
        }
        $limit = self::LIST_DEFAULT;
        if (isset($q['limit'])) {
            $limit = is_string($q['limit']) && preg_match('/^[1-9]\d{0,3}\z/', $q['limit']) === 1 ? (int) $q['limit'] : 0;
            if ($limit < 1 || $limit > self::LIST_MAX) {
                throw new ValidationException('limit must be an integer from 1 to ' . self::LIST_MAX . '.');
            }
        }
        $result = $this->installations()->list($filters, $limit);
        return $this->json($request, ['installations' => $result['rows'], 'total' => $result['total']]);
    }

    private function revokeBatch(ServerRequestInterface $request): ResponseInterface
    {
        $params = $this->getJsonBodyParams($request);
        $keyId  = $params['client_key_id'] ?? null;
        if (!is_string($keyId) || !self::isUuid($keyId)) {
            throw new ValidationException('client_key_id must be a UUID.');
        }
        $from = self::isoDate($params['from'] ?? null, 'from');
        $to   = self::isoDate($params['to'] ?? null, 'to');
        if ($from >= $to) {
            throw new ValidationException('from must be earlier than to.');
        }
        if ($to->getTimestamp() - $from->getTimestamp() > self::BATCH_MAX_DAYS * 86400) {
            throw new ValidationException('The window from–to may be at most ' . self::BATCH_MAX_DAYS . ' days.');
        }
        $keyId = strtolower($keyId);
        if (( new ClientKeyRepository($this->db()) )->find($keyId) === null) {
            throw new NotFoundException('Client key not found.');
        }
        return $this->json($request, ['revoked' => $this->installations()->revokeRegisteredBetween($keyId, $from, $to)]);
    }

    private function revokeOne(ServerRequestInterface $request, string $id): ResponseInterface
    {
        if (!$this->installations()->revoke($id)) {
            if ($this->installations()->find($id) === null) {
                throw new NotFoundException('Installation not found.');
            }
            throw new ConflictException('This installation is already revoked.');
        }
        return $this->noContent($request);
    }

    private function setLimit(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $installation = $this->installations()->setRateLimit($id, $this->rateLimitFromBody($request));
        if ($installation === null) {
            throw new NotFoundException('Installation not found.');
        }
        return $this->json($request, ['installation' => $installation]);
    }

    private function installations(): InstallationRepository
    {
        return new InstallationRepository($this->db());
    }
}
