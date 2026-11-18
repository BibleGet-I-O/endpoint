<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Admin;

use BibleGet\Api\Http\Enum\StatusCode;
use BibleGet\Api\Http\Exception\ConflictException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Repositories\ClientKeyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Plugin client keys, one per plugin release (role bibleget_admin):
 *
 *   GET  /admin/client-keys                 listing (key_prefix only)
 *   POST /admin/client-keys                 {plugin, plugin_version} → the plaintext key, once
 *   POST /admin/client-keys/{id}/revoke     stops new registrations; existing installations keep working
 */
class ClientKeyAdminHandler extends AbstractAdminHandler
{
    public const PLUGINS         = ['wordpress', 'google-docs', 'libreoffice', 'openoffice', 'msword', 'webword'];
    public const VERSION_PATTERN = '/^[0-9A-Za-z.+_-]{1,50}\z/';

    protected function dispatch(ServerRequestInterface $request, string $method, array $path, string $admin): ResponseInterface
    {
        if ($path === []) {
            self::requireMethod($method, ['GET', 'POST']);
            return $method === 'GET' ? $this->listKeys($request) : $this->mintKey($request);
        }
        if (count($path) !== 2 || $path[1] !== 'revoke') {
            throw new NotFoundException();
        }
        $id = self::pathId($path[0]);
        self::requireMethod($method, ['POST']);
        return $this->revokeKey($request, $id);
    }

    private function listKeys(ServerRequestInterface $request): ResponseInterface
    {
        $keys = $this->keys()->list();
        return $this->json($request, ['client_keys' => $keys, 'total' => count($keys)]);
    }

    private function mintKey(ServerRequestInterface $request): ResponseInterface
    {
        $params  = $this->getJsonBodyParams($request);
        $plugin  = $params['plugin'] ?? null;
        $version = $params['plugin_version'] ?? null;
        if (!is_string($plugin) || !in_array($plugin, self::PLUGINS, true)) {
            throw new ValidationException('plugin must be one of ' . implode(', ', self::PLUGINS) . '.');
        }
        if (!is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new ValidationException('plugin_version is required: 1 to 50 letters, digits or . + _ -');
        }
        $minted = $this->keys()->mint($plugin, $version);
        return $this->json($request, [
            'message' => 'Save this key now: it will not be shown again. It goes into this plugin release\'s build.',
            'key'     => $minted['plain'],
            'record'  => $this->keys()->find($minted['id']),
        ], StatusCode::CREATED);
    }

    private function revokeKey(ServerRequestInterface $request, string $id): ResponseInterface
    {
        if (!$this->keys()->revoke($id)) {
            if ($this->keys()->find($id) === null) {
                throw new NotFoundException('Client key not found.');
            }
            throw new ConflictException('This client key is already revoked.');
        }
        return $this->noContent($request);
    }

    private function keys(): ClientKeyRepository
    {
        return new ClientKeyRepository($this->db());
    }
}
