<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Admin;

use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Repositories\VersionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Edition flags (role bibleget_admin): whether the `reader` credential may serve an
 * edition's chapters whole, and whether the edition is the liturgical text of its language:
 *
 *   GET   /admin/versions            every Bible edition with its copyright, reader_full_text and liturgical flags
 *   GET   /admin/versions/{sigla}    one edition
 *   PATCH /admin/versions/{sigla}    {reader_full_text?: bool, liturgical?: bool}, at least one
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
            $this->patch($request, $sigla);
        }
        $version = $this->versions()->find($sigla);
        if ($version === null) {
            throw new NotFoundException('Bible version not found.');
        }
        return $this->json($request, $version);
    }

    private function patch(ServerRequestInterface $request, string $sigla): void
    {
        $body  = $this->getJsonBodyParams($request);
        $flags = [];
        foreach (['reader_full_text', 'liturgical'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if (!is_bool($body[$field])) {
                throw new ValidationException($field . ' must be true or false.');
            }
            $flags[$field] = $body[$field];
        }
        if ($flags === []) {
            throw new ValidationException('reader_full_text and/or liturgical must be given, as true or false.');
        }
        $versions = $this->versions();
        if ($versions->find($sigla) === null) {
            throw new NotFoundException('Bible version not found.');
        }
        if (isset($flags['reader_full_text'])) {
            $versions->setReaderFullText($sigla, $flags['reader_full_text']);
        }
        if (isset($flags['liturgical'])) {
            $versions->setLiturgical($sigla, $flags['liturgical']);
        }
    }

    private function versions(): VersionRepository
    {
        return new VersionRepository($this->db());
    }
}
