<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Admin;

use BibleGet\Api\Http\Exception\ConflictException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Repositories\ApiKeyRepository;
use BibleGet\Api\Repositories\ApplicationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Application review and project-key limits (role bibleget_admin):
 *
 *   GET   /admin/applications[?status=pending|approved|rejected|revoked]
 *   GET   /admin/applications/{id}           the application (system ones included) and its keys
 *   POST  /admin/applications/{id}/approve   pending|rejected → approved
 *   POST  /admin/applications/{id}/reject    pending → rejected
 *   POST  /admin/applications/{id}/revoke    approved → revoked (deactivates its keys)
 *   PATCH /admin/api-keys/{id}               {rate_limit_per_hour}
 *   POST  /admin/api-keys/{id}/revoke        deactivates one key (system keys included); 409 if inactive
 *
 * Review bodies take an optional `notes`; any other transition is a 409.
 */
class ApplicationAdminHandler extends AbstractAdminHandler
{
    private const STATUSES = ['pending', 'approved', 'rejected', 'revoked'];
    private const ACTIONS  = ['approve', 'reject', 'revoke'];

    protected function dispatch(ServerRequestInterface $request, string $method, array $path, string $admin): ResponseInterface
    {
        if (( $this->requestPathParams[0] ?? '' ) === 'api-keys') {
            return $this->dispatchKey($request, $method, $path);
        }

        if ($path === []) {
            self::requireMethod($method, ['GET']);
            return $this->listApplications($request);
        }
        if (count($path) === 1) {
            $id = self::pathId($path[0]);
            self::requireMethod($method, ['GET']);
            return $this->showApplication($request, $id);
        }
        if (count($path) !== 2 || !in_array($path[1], self::ACTIONS, true)) {
            throw new NotFoundException();
        }
        $id = self::pathId($path[0]);
        self::requireMethod($method, ['POST']);
        return $this->review($request, $id, $path[1], $admin);
    }

    /** @param list<string> $path */
    private function dispatchKey(ServerRequestInterface $request, string $method, array $path): ResponseInterface
    {
        $n = count($path);
        if ($n < 1 || $n > 2 || ( $n === 2 && $path[1] !== 'revoke' )) {
            throw new NotFoundException();
        }
        $id = self::pathId($path[0]);
        if ($n === 1) {
            self::requireMethod($method, ['PATCH']);
            return $this->setKeyLimit($request, $id);
        }
        self::requireMethod($method, ['POST']);
        $done = ( new ApiKeyRepository($this->db()) )->deactivate($id);
        if ($done === null) {
            throw new NotFoundException('API key not found.');
        }
        if ($done === false) {
            throw new ConflictException('This API key is already inactive.');
        }
        return $this->noContent($request);
    }

    private function showApplication(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $app = $this->apps()->findIncludingSystem($id);
        if ($app === null) {
            throw new NotFoundException('Application not found.');
        }
        return $this->json($request, [
            'application' => $app,
            'keys'        => ( new ApiKeyRepository($this->db()) )->listForApplication($id),
        ]);
    }

    private function listApplications(ServerRequestInterface $request): ResponseInterface
    {
        $status = $request->getQueryParams()['status'] ?? null;
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new ValidationException('status must be one of ' . implode(', ', self::STATUSES) . '.');
        }
        $apps = $this->apps()->list(is_string($status) ? $status : null);
        return $this->json($request, ['applications' => $apps, 'total' => count($apps)]);
    }

    private function review(ServerRequestInterface $request, string $id, string $action, string $admin): ResponseInterface
    {
        $notes = self::validNotes($this->getJsonBodyParams($request)['notes'] ?? null);
        $done  = match ($action) {
            'approve' => $this->apps()->approve($id, $admin, $notes),
            'reject'  => $this->apps()->reject($id, $admin, $notes),
            default   => $this->apps()->revoke($id, $admin, $notes),
        };
        $app = $this->apps()->find($id);
        if ($app === null) {
            throw new NotFoundException('Application not found.');
        }
        if (!$done) {
            $target = ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked'][$action];
            $from   = implode(' or ', ApplicationRepository::TRANSITIONS[$target]);
            $status = is_string($app['status'] ?? null) ? $app['status'] : 'unknown';
            throw new ConflictException("Only a {$from} application can be {$target}; this one is {$status}.");
        }
        return $this->json($request, ['application' => $app]);
    }

    private function setKeyLimit(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $limit = $this->rateLimitFromBody($request);
        $key   = ( new ApiKeyRepository($this->db()) )->setRateLimit($id, $limit);
        if ($key === null) {
            throw new NotFoundException('API key not found.');
        }
        return $this->json($request, ['key' => $key]);
    }

    private static function validNotes(mixed $v): ?string
    {
        if ($v === null || ( is_string($v) && trim($v) === '' )) {
            return null;
        }
        if (!is_string($v) || !self::isCleanMultilineText($v) || mb_strlen(trim($v), 'UTF-8') > 1000) {
            throw new ValidationException('notes must be text of at most 1000 characters; line breaks and tabs are the only control characters allowed.');
        }
        return trim($v);
    }

    private function apps(): ApplicationRepository
    {
        return new ApplicationRepository($this->db());
    }
}
