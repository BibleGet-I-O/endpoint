<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers\Review;

use BibleGet\Api\Handlers\Admin\AbstractAdminHandler;
use BibleGet\Api\Handlers\Admin\VersionAdminHandler;
use BibleGet\Api\Http\Exception\ConflictException;
use BibleGet\Api\Http\Exception\NotFoundException;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Repositories\BtuRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Review of the links from an edition's verses to CBTUDR units (role bibleget_reviewer):
 *
 *   GET   /review/editions                                     each edition's counts of draft, reviewed and rule links
 *   GET   /review/editions/{sigla}/chapters                    chapters with drafts, least confident first
 *   GET   /review/editions/{sigla}/books/{book}/chapters/{c}   the chapter's links, and the units of their stretches
 *   POST  /review/editions/{sigla}/books/{book}/chapters/{c}/accept   accept the chapter's remaining drafts
 *   PATCH /review/links/{id}   exactly one of {"action":"accept"}, {"action":"relink","units":[…]},
 *                              {"action":"none"}, {"action":"split","note":"…"}, {"kind":"same"|"corresponding"}
 *
 * A relink may use only the units of the link's own stretch. Links made by rule are not reviewed (422).
 * Who reviewed a link is recorded, but never returned.
 */
class ReviewHandler extends AbstractAdminHandler
{
    private const KINDS    = ['same', 'corresponding'];
    private const MAX_NOTE = 1000;

    protected function dispatch(ServerRequestInterface $request, string $method, array $path, string $admin): ResponseInterface
    {
        return match ($path[0] ?? '') {
            'editions' => $this->editions($request, $method, array_slice($path, 1), $admin),
            'links'    => $this->patchLink($request, $method, array_slice($path, 1), $admin),
            default    => throw new NotFoundException(),
        };
    }

    /** @param list<string> $path the segments after /review/editions */
    private function editions(ServerRequestInterface $request, string $method, array $path, string $sub): ResponseInterface
    {
        if ($path === []) {
            self::requireMethod($method, ['GET']);
            return $this->json($request, ['editions' => $this->repo()->editions()]);
        }
        $sigla = self::sigla($path[0]);
        $rest  = array_slice($path, 1);
        if ($rest === ['chapters']) {
            self::requireMethod($method, ['GET']);
            return $this->json($request, ['chapters' => $this->repo()->chaptersWithDrafts($sigla)]);
        }
        if (count($rest) < 4 || $rest[0] !== 'books' || $rest[2] !== 'chapters' || count($rest) > 5) {
            throw new NotFoundException();
        }
        $book    = self::number($rest[1], 73);
        $chapter = self::number($rest[3], 200);
        if (count($rest) === 5) {
            if ($rest[4] !== 'accept') {
                throw new NotFoundException();
            }
            self::requireMethod($method, ['POST']);
            return $this->json($request, ['accepted' => $this->repo()->acceptChapter($sigla, $book, $chapter, $sub)]);
        }
        self::requireMethod($method, ['GET']);
        $data          = $this->repo()->chapter($sigla, $book, $chapter);
        $data['links'] = array_map(self::public(...), $data['links']);
        return $this->json($request, $data);
    }

    /** @param list<string> $path the segments after /review/links */
    private function patchLink(ServerRequestInterface $request, string $method, array $path, string $sub): ResponseInterface
    {
        if (count($path) !== 1 || preg_match('/^[1-9]\d{0,17}\z/', $path[0]) !== 1) {
            throw new NotFoundException();
        }
        self::requireMethod($method, ['PATCH']);
        $found = $this->repo()->find((int) $path[0]);
        if ($found === null) {
            throw new NotFoundException('Link not found.');
        }
        if ($found['link']['status'] === 'rule') {
            throw new ValidationException('Links made by rule are not reviewed.');
        }
        $change = $this->change($this->getJsonBodyParams($request), $found);
        $link   = $this->repo()->review($found['link']['id'], $change, $sub);
        if ($link === null) {
            throw new ConflictException('The link changed while it was being reviewed: reload the chapter.');
        }
        return $this->json($request, self::public($link));
    }

    /**
     * The one change the body asks for, validated.
     *
     * @param array<string, mixed> $body
     * @param array{edition: string, book: int, link: array{stretch: ?string}} $found
     * @return array{action?: string, units?: list<string>, note?: string, kind?: string}
     */
    private function change(array $body, array $found): array
    {
        if (count($body) === 1 && array_key_exists('kind', $body)) {
            if (!in_array($body['kind'], self::KINDS, true)) {
                throw new ValidationException('kind must be same or corresponding.');
            }
            return ['kind' => $body['kind']];
        }
        $action  = $body['action'] ?? null;
        $allowed = match ($action) {
            'relink' => ['action', 'units'],
            'split'  => ['action', 'note'],
            default  => ['action'],
        };
        $extra = array_diff(array_map('strval', array_keys($body)), $allowed);
        if (!is_string($action) || $extra !== []) {
            throw new ValidationException('The body must be one of: accept, relink with units, none, split with a note, or kind.');
        }
        return match ($action) {
            'accept', 'none' => ['action' => $action],
            'relink'         => ['action' => 'relink', 'units' => $this->relinkUnits($body['units'] ?? null, $found)],
            'split'          => ['action' => 'split', 'note' => self::note($body['note'] ?? null)],
            default          => throw new ValidationException('Unknown action.'),
        };
    }

    /**
     * @param array{edition: string, book: int, link: array{stretch: ?string}} $found
     * @return list<string>
     */
    private function relinkUnits(mixed $units, array $found): array
    {
        if (!is_array($units) || $units === [] || !array_is_list($units)) {
            throw new ValidationException('units must be a non-empty list of unit ids.');
        }
        $stretch = $found['link']['stretch'];
        $allowed = $stretch === null ? [] : $this->repo()->stretchUnits($found['edition'], $found['book'], $stretch);
        $out     = [];
        foreach ($units as $u) {
            if (!is_string($u) || !in_array($u, $allowed, true) || in_array($u, $out, true)) {
                throw new ValidationException('Each unit must belong to the link\'s stretch, once.');
            }
            $out[] = $u;
        }
        return $out;
    }

    private static function note(mixed $note): string
    {
        if (!is_string($note) || trim($note) === '' || mb_strlen($note) > self::MAX_NOTE) {
            throw new ValidationException('note must be a non-empty string of at most ' . self::MAX_NOTE . ' characters.');
        }
        return trim($note);
    }

    private static function sigla(string $v): string
    {
        if (preg_match(VersionAdminHandler::SIGLA_PATTERN, $v) !== 1) {
            throw new NotFoundException();
        }
        return $v;
    }

    private static function number(string $v, int $max): int
    {
        if (preg_match('/^[1-9]\d{0,2}\z/', $v) !== 1 || (int) $v > $max) {
            throw new NotFoundException();
        }
        return (int) $v;
    }

    /**
     * A link as answered: everything but who reviewed it.
     *
     * @param array{reviewed_by: ?string} $link
     * @return array<string, mixed>
     */
    private static function public(array $link): array
    {
        unset($link['reviewed_by']);
        return $link;
    }

    private function repo(): BtuRepository
    {
        return new BtuRepository($this->db());
    }
}
