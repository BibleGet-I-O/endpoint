<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Auth\CredentialFactory;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

/**
 * Zitadel's signing keys, from `{issuer}/oauth/v2/keys`.
 *
 * The JWKS document is cached in a file (JWKS_CACHE_PATH, default
 * logs/jwks.json) for one hour. A token whose `kid` is not in the cached set
 * triggers one refetch (Zitadel rotated its keys), but not when the set was
 * fetched less than a minute ago, so tokens with made-up kids cannot turn
 * every request into a download.
 *
 * Only RSA keys are kept, and every key is bound to RS256: a token signed with
 * any other algorithm (none, HS256 with the public key as secret, ...) fails
 * the library's algorithm check.
 *
 * ZITADEL_JWKS_FILE (tests only) reads the JWKS from a local file instead.
 */
class JwksProvider
{
    public const ALGORITHM     = 'RS256';
    public const DEFAULT_CACHE = 'logs/jwks.json';
    private const MIN_REFETCH  = 60;

    private readonly ?string $issuer;
    private readonly string $cachePath;
    private readonly ?string $jwksFile;
    /** @var \Closure(string): string */
    private readonly \Closure $fetcher;
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @var array<string, Key>|null */
    private ?array $keys   = null;
    private int $fetchedAt = 0;

    /**
     * @param string|null                 $issuer    Zitadel issuer URL (default ZITADEL_ISSUER)
     * @param string|null                 $cachePath cache file (default JWKS_CACHE_PATH, else logs/jwks.json)
     * @param string|null                 $jwksFile  local JWKS file to use instead of fetching (default ZITADEL_JWKS_FILE)
     * @param int                         $ttl       cache lifetime in seconds
     * @param (\Closure(string): string)|null $fetcher downloads a URL, injectable for tests
     * @param (\Closure(): int)|null      $clock     current UNIX time, injectable for tests
     */
    public function __construct(
        ?string $issuer = null,
        ?string $cachePath = null,
        ?string $jwksFile = null,
        private readonly int $ttl = 3600,
        ?\Closure $fetcher = null,
        ?\Closure $clock = null
    ) {
        $this->issuer    = self::nonEmpty($issuer ?? CredentialFactory::env('ZITADEL_ISSUER'));
        $this->cachePath = self::nonEmpty($cachePath ?? CredentialFactory::env('JWKS_CACHE_PATH'))
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::DEFAULT_CACHE;
        $this->jwksFile  = self::nonEmpty($jwksFile ?? CredentialFactory::env('ZITADEL_JWKS_FILE'));
        $this->fetcher   = $fetcher ?? self::httpGet(...);
        $this->clock     = $clock ?? static fn (): int => time();
    }

    /**
     * The verification keys by kid, refreshed once when $kid is not among them.
     *
     * @return array<string, Key>
     * @throws \RuntimeException when no key set can be obtained
     */
    public function keysFor(string $kid): array
    {
        $now  = ( $this->clock )();
        $keys = $this->keys !== null && $now - $this->fetchedAt < $this->ttl ? $this->keys : $this->load();
        if (!isset($keys[$kid]) && $now - $this->fetchedAt >= self::MIN_REFETCH) {
            $keys = $this->refresh();
        }
        return $keys;
    }

    /** @return array<string, Key> */
    private function load(): array
    {
        if ($this->jwksFile !== null) {
            return $this->refresh();
        }
        $cached = $this->readCache();
        if ($cached !== null) {
            return $cached;
        }
        return $this->refresh();
    }

    /** @return array<string, Key> */
    private function refresh(): array
    {
        $now = ( $this->clock )();
        if ($this->jwksFile !== null) {
            $json = @file_get_contents($this->jwksFile);
            if ($json === false) {
                throw new \RuntimeException('ZITADEL_JWKS_FILE is not readable.');
            }
        } else {
            if ($this->issuer === null) {
                throw new \RuntimeException('ZITADEL_ISSUER is not set.');
            }
            $json = ( $this->fetcher )(rtrim($this->issuer, '/') . '/oauth/v2/keys');
        }
        $keys = self::parse($json);
        if ($this->jwksFile === null) {
            $this->writeCache($json, $now);
        }
        $this->keys      = $keys;
        $this->fetchedAt = $now;
        return $keys;
    }

    /** @return array<string, Key>|null null when the cache is missing, stale or unreadable */
    private function readCache(): ?array
    {
        $raw = is_file($this->cachePath) ? @file_get_contents($this->cachePath) : false;
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_int($data['fetched_at'] ?? null) || !is_string($data['jwks'] ?? null)) {
            return null;
        }
        $age = ( $this->clock )() - $data['fetched_at'];
        if ($age < 0 || $age >= $this->ttl) {
            return null;
        }
        try {
            $keys = self::parse($data['jwks']);
        } catch (\RuntimeException) {
            return null;
        }
        $this->keys      = $keys;
        $this->fetchedAt = $data['fetched_at'];
        return $keys;
    }

    private function writeCache(string $json, int $now): void
    {
        $dir = dirname($this->cachePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // an unwritable cache only costs a refetch per request
        }
        $tmp = $this->cachePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $out = json_encode(['fetched_at' => $now, 'jwks' => $json]);
        if ($out !== false && @file_put_contents($tmp, $out) !== false) {
            @rename($tmp, $this->cachePath);
        }
        @unlink($tmp);
    }

    /**
     * @return array<string, Key>
     * @throws \RuntimeException on a document holding no usable RSA key
     */
    private static function parse(string $json): array
    {
        $doc = json_decode($json, true);
        if (!is_array($doc) || !is_array($doc['keys'] ?? null)) {
            throw new \RuntimeException('The JWKS document is malformed.');
        }
        $rsa = [];
        foreach ($doc['keys'] as $jwk) {
            if (
                is_array($jwk) && ( $jwk['kty'] ?? null ) === 'RSA' && is_string($jwk['kid'] ?? null)
                && in_array($jwk['alg'] ?? self::ALGORITHM, [self::ALGORITHM], true)
                && in_array($jwk['use'] ?? 'sig', ['sig'], true)
            ) {
                $jwk['alg'] = self::ALGORITHM;
                $rsa[]      = $jwk;
            }
        }
        if ($rsa === []) {
            throw new \RuntimeException('The JWKS document holds no RS256 signing key.');
        }
        try {
            return JWK::parseKeySet(['keys' => $rsa], self::ALGORITHM);
        } catch (\Throwable $e) {
            throw new \RuntimeException('The JWKS document could not be parsed: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function httpGet(string $url): string
    {
        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => false]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException('Could not fetch the JWKS from ' . $url);
        }
        return $body;
    }

    private static function nonEmpty(?string $v): ?string
    {
        return $v === null || trim($v) === '' ? null : trim($v);
    }
}
