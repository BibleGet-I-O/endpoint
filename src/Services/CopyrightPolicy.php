<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Util\StringUtils;

/**
 * Who reads a copyrighted edition whole: the one rule for quotes and search.
 *
 * An edition with versions_available.copyright = 1 gives a request at most VERSE_CAP verses, unless the request's
 * credential has the `reader` scope (the website's key for signed-in readers with a verified email) and the edition
 * is flagged reader_full_text. Without a credential (credentials not required), the edition is capped too.
 */
final class CopyrightPolicy
{
    public const VERSE_CAP = 30;

    /** @var array<string, true> */
    private array $copyrighted;

    /** @var array<string, true> */
    private array $readerFullText;

    /**
     * @param array<int, string> $copyrighted    the copyrighted editions' sigla
     * @param array<int, string> $readerFullText the editions the reader scope may read whole
     */
    public function __construct(array $copyrighted, array $readerFullText)
    {
        $this->copyrighted    = array_fill_keys($copyrighted, true);
        $this->readerFullText = array_fill_keys($readerFullText, true);
    }

    /** The policy as versions_available states it. */
    public static function fromDatabase(\PDO $pdo): self
    {
        $stmt = $pdo->query('SELECT sigla, copyright, reader_full_text FROM versions_available');
        if ($stmt === false) {
            throw new \RuntimeException('versions_available could not be read');
        }
        $copyrighted    = [];
        $readerFullText = [];
        while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $sigla = StringUtils::asString($row['sigla']);
            if (StringUtils::asInt($row['copyright']) === 1) {
                $copyrighted[] = $sigla;
            }
            if (in_array($row['reader_full_text'] ?? false, [true, 't', 1, '1'], true)) {
                $readerFullText[] = $sigla;
            }
        }
        return new self($copyrighted, $readerFullText);
    }

    /** Whether $sigla gives this credential at most VERSE_CAP verses. */
    public function isCapped(string $sigla, ?Credential $credential): bool
    {
        return isset($this->copyrighted[$sigla]) && !$this->fullTextAllowed($sigla, $credential);
    }

    /** Whether this credential may read $sigla whole past the cap: the reader scope, on a reader_full_text edition. */
    public function fullTextAllowed(string $sigla, ?Credential $credential): bool
    {
        return $credential !== null && $credential->scope === 'reader' && isset($this->readerFullText[$sigla]);
    }
}
