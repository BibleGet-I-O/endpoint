<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Admin;

use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Repositories\VersionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Whether the `reader` credential may serve an edition's chapters whole (role bibleget_admin):
 *
 *   GET   /admin/versions            every Bible edition with its copyright and reader_full_text flags
 *   GET   /admin/versions/{sigla}    one edition
 *   PATCH /admin/versions/{sigla}    {reader_full_text: bool}
 */
class VersionAdminHandler extends AbstractAdminHandler
{
    public const SIGLA_PATTERN = '/^[A-Z0-9]{2,10}\z/';

    protected function dispatch(ServerRequestInterface $request, string $method, array $path, string $admin): ResponseInterface
    {
        if ($path === []) {
            self::requireMethod($method, ['GET']);
            $versions = $this->versions()->list();
            return $this->json($request, ['versions' => $versions, 'total' => count($versions)]);
        }
        if (count($path) !== 1 || preg_match(self::SIGLA_PATTERN, $path[0]) !== 1) {
            throw new NotFoundException();
        }
        self::requireMethod($method, ['GET', 'PATCH']);
        $sigla = $path[0];
        if ($method === 'PATCH') {
            $on = $this->getJsonBodyParams($request)['reader_full_text'] ?? null;
            if (!is_bool($on)) {
                throw new ValidationException('reader_full_text must be true or false.');
            }
            if (!$this->versions()->setReaderFullText($sigla, $on)) {
                throw new NotFoundException('Bible version not found.');
            }
        }
        $version = $this->versions()->find($sigla);
        if ($version === null) {
            throw new NotFoundException('Bible version not found.');
        }
        return $this->json($request, $version);
    }

    private function versions(): VersionRepository
    {
        return new VersionRepository($this->db());
    }
}
