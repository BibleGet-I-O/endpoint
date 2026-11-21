<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Database\Connection;
use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Http\Logs\LoggerFactory;
use BibleGet\Api\Services\KeywordSearchService;
use BibleGet\Api\Util\AppVersion;
use BibleGet\Api\Util\SearchUtils;
use BibleGet\Api\Util\StringUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /search/suggest: the words of one edition that start with a prefix, most frequent first, for keyword search
 * suggestions as a reader types. The prefix is matched unaccented and case-insensitively against search_vocabulary
 * (migration 058), which holds only words keyword search accepts. Words are not verses: no copyright cap applies.
 */
class SuggestSearchHandler extends AbstractHandler
{
    private const DEFAULT_LIMIT = 8;
    private const MAX_LIMIT     = 20;
    private const MIN_PREFIX    = 2;
    private const MAX_PREFIX    = 40;

    /**
     * Bound: the sigla, the LIKE pattern (foldedPattern), the limit. The pattern arrives finished, so the planner
     * sees a constant prefix and searches the (sigla, folded text_pattern_ops) index by range, rather than testing
     * every word of the edition: unaccent() is only STABLE, so a pattern built in this query would not be constant.
     */
    public const QUERY = 'SELECT word, freq FROM search_vocabulary
          WHERE sigla = ? AND folded LIKE ?
          ORDER BY freq DESC, word LIMIT ?';

    /**
     * The prefix folded as search_vocabulary.folded is (unaccent(lower())), as a LIKE prefix pattern. The prefix is
     * letters only (prefixParam), so it holds no LIKE wildcard to escape.
     */
    public static function foldedPattern(\PDO $pdo, string $prefix): string
    {
        $stmt = $pdo->prepare('SELECT unaccent(lower(?))');
        $stmt->execute([$prefix]);
        return StringUtils::asString($stmt->fetchColumn()) . '%';
    }

    public static function resetCache(): void
    {
        KeywordSearchService::resetCache();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Nyholm\Psr7\Response(200, [], null, $request->getProtocolVersion(), 'OK');
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);
        $this->validateRequestContentType($request);
        $params   = $this->getRequestParams($request);
        $response = $this->initResponse($request, 'application/json');

        $versions = SearchUtils::parseVersionsParam($params['version'] ?? null);
        if (count($versions) > 1) {
            throw new ValidationException('Suggestions are for one version at a time.');
        }
        $pdo         = Connection::getConnection();
        [$validated] = ( new KeywordSearchService($pdo, LoggerFactory::create('api')) )
            ->validateVersions($versions, '/search/suggest');
        $prefix      = self::prefixParam($params['prefix'] ?? null);
        $limit       = SearchUtils::parseLimitParam($params['limit'] ?? null, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        $stmt = $pdo->prepare(self::QUERY);
        $stmt->execute([$validated[0], self::foldedPattern($pdo, $prefix), $limit]);
        $suggestions = [];
        while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $suggestions[] = ['word' => StringUtils::asString($row['word']), 'count' => StringUtils::asInt($row['freq'])];
        }

        $response = $this->jsonResponse($response, [
            'suggestions' => $suggestions,
            'info'        => ['ENDPOINT_VERSION' => AppVersion::current()],
        ]);
        return $this->withCacheHeaders($request, $response);
    }

    /**
     * The typed beginning of a word: letters only (so no LIKE wildcard gets in), MIN_PREFIX to MAX_PREFIX of them.
     *
     * @throws ValidationException
     */
    private static function prefixParam(mixed $raw): string
    {
        $prefix = is_string($raw) ? trim($raw) : '';
        $length = mb_strlen($prefix);
        if ($length < self::MIN_PREFIX || $length > self::MAX_PREFIX || preg_match('/^\p{L}+$/u', $prefix) !== 1) {
            throw new ValidationException(
                'The prefix parameter must be ' . self::MIN_PREFIX . ' to ' . self::MAX_PREFIX . ' letters.'
            );
        }
        return $prefix;
    }
}
