<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Auth\CredentialFactory;
use BibleGet\Api\Http\Logs\LoggerFactory;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;

/**
 * Zitadel's signing keys, from `{issuer}/oauth/v2/keys`.
 *
 * The JWKS document is cached in a file (JWKS_CACHE_PATH, default
 * logs/jwks.json) for one hour, with the time of the last fetch attempt. A
 * token whose `kid` is not in the cached set, or a cache past its lifetime,
 * triggers a refetch, but never within a minute of the last attempt (success
 * or failure, as recorded in the file), so tokens with made-up kids or an
 * unreachable Zitadel cannot turn every request into a download. When a fetch
 * fails, the cached keys are used even past their lifetime; only with no keys
 * at all does keysFor() throw.
 *
 * Only RSA keys are kept, and every key is bound to RS256: a token signed with
 * any other algorithm (none, HS256 with the public key as secret, ...) fails
 * the library's algorithm check.
 *
 * ZITADEL_JWKS_FILE (tests only) reads the JWKS from a local file instead. It
 * is ignored when APP_ENV=production (an error is logged once per process).
 */
class JwksProvider
{
    public const ALGORITHM     = 'RS256';
    public const DEFAULT_CACHE = 'logs/jwks.json';
    private const MIN_REFETCH  = 60;

    private static bool $jwksFileWarned = false;

    private readonly ?string $issuer;
    private readonly string $cachePath;
    private readonly ?string $jwksFile;
    /** @var \Closure(string): string */
    private readonly \Closure $fetcher;
    /** @var \Closure(): int */
    private readonly \Closure $clock;
    private ?LoggerInterface $logger;

    /** @var array<string, Key>|null */
    private ?array $keys           = null;
    private ?string $jwksJson      = null;
    private int $fetchedAt         = 0;
    private int $attemptedAt       = 0;
    private bool $cacheRead        = false;
    private ?\Throwable $lastError = null;

    /**
     * @param string|null                 $issuer    Zitadel issuer URL (default ZITADEL_ISSUER)
     * @param string|null                 $cachePath cache file (default JWKS_CACHE_PATH, else logs/jwks.json)
     * @param string|null                 $jwksFile  local JWKS file to use instead of fetching (default ZITADEL_JWKS_FILE; ignored in production)
     * @param int                         $ttl       cache lifetime in seconds
     * @param (\Closure(string): string)|null $fetcher downloads a URL, injectable for tests
     * @param (\Closure(): int)|null      $clock     current UNIX time, injectable for tests
     * @param LoggerInterface|null        $logger    default: the api-error log
     */
    public function __construct(
        ?string $issuer = null,
        ?string $cachePath = null,
        ?string $jwksFile = null,
        private readonly int $ttl = 3600,
        ?\Closure $fetcher = null,
        ?\Closure $clock = null,
        ?LoggerInterface $logger = null
    ) {
        $this->logger    = $logger;
        $this->issuer    = self::nonEmpty($issuer ?? CredentialFactory::env('ZITADEL_ISSUER'));
        $this->cachePath = self::nonEmpty($cachePath ?? CredentialFactory::env('JWKS_CACHE_PATH'))
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::DEFAULT_CACHE;
        $this->jwksFile  = $this->allowedJwksFile(self::nonEmpty($jwksFile ?? CredentialFactory::env('ZITADEL_JWKS_FILE')));
        $this->fetcher   = $fetcher ?? self::httpGet(...);
        $this->clock     = $clock ?? static fn (): int => time();
    }

    /**
     * The verification keys by kid, refreshed (at most once a minute) when the
     * set is past its lifetime or $kid is not among them.
     *
     * @return array<string, Key>
     * @throws \RuntimeException when no key set can be obtained
     */
    public function keysFor(string $kid): array
    {
        $now = ( $this->clock )();
        if (!$this->cacheRead && $this->jwksFile === null) {
            $this->readCache($now);
        }
        $stale   = $this->keys === null || $now - $this->fetchedAt >= $this->ttl;
        $unknown = $this->keys !== null && !isset($this->keys[$kid]);
        if (( $stale || $unknown ) && $now - $this->attemptedAt >= self::MIN_REFETCH) {
            $this->refresh($now);
        }
        if ($this->keys === null) {
            $why = $this->lastError?->getMessage() ?? 'a fetch was attempted less than a minute ago';
            throw new \RuntimeException('No JWKS is available: ' . $why, 0, $this->lastError);
        }
        return $this->keys;
    }

    /** The local JWKS file, unless in production, where it is ignored. */
    private function allowedJwksFile(?string $file): ?string
    {
        if ($file === null || CredentialFactory::currentEnv() !== 'production') {
            return $file;
        }
        if (!self::$jwksFileWarned) {
            self::$jwksFileWarned = true;
            $this->logger()->error('ZITADEL_JWKS_FILE is set in production and is ignored; the JWKS is fetched from the issuer.');
        }
        return null;
    }

    /** Fetches the key set; on failure records the attempt and keeps the keys already held. */
    private function refresh(int $now): void
    {
        $this->attemptedAt = $now;
        try {
            $json = $this->download();
            $keys = self::parse($json);
        } catch (\Throwable $e) {
            $this->lastError = $e;
            $this->logger()->error('JWKS fetch failed' . ( $this->keys !== null ? '; using the cached keys' : '' ) . ': ' . $e->getMessage());
            if ($this->jwksFile === null) {
                $this->writeCache();
            }
            return;
        }
        $this->keys      = $keys;
        $this->jwksJson  = $json;
        $this->fetchedAt = $now;
        $this->lastError = null;
        if ($this->jwksFile === null) {
            $this->writeCache();
        }
    }

    private function download(): string
    {
        if ($this->jwksFile !== null) {
            $json = @file_get_contents($this->jwksFile);
            if ($json === false) {
                throw new \RuntimeException('ZITADEL_JWKS_FILE is not readable.');
            }
            return $json;
        }
        if ($this->issuer === null) {
            throw new \RuntimeException('ZITADEL_ISSUER is not set.');
        }
        return ( $this->fetcher )(rtrim($this->issuer, '/') . '/oauth/v2/keys');
    }

    /**
     * Loads the cache file once: the keys (even past their lifetime) and the
     * time of the last fetch attempt. Times in the future are ignored.
     */
    private function readCache(int $now): void
    {
        $this->cacheRead = true;
        $raw             = is_file($this->cachePath) ? @file_get_contents($this->cachePath) : false;
        $data            = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        $fetchedAt   = is_int($data['fetched_at'] ?? null) && $data['fetched_at'] <= $now ? $data['fetched_at'] : 0;
        $attemptedAt = is_int($data['attempted_at'] ?? null) ? $data['attempted_at'] : $fetchedAt;
        if ($attemptedAt <= $now) {
            $this->attemptedAt = max($this->attemptedAt, $attemptedAt);
        }
        if (!is_string($data['jwks'] ?? null)) {
            return;
        }
        try {
            $this->keys = self::parse($data['jwks']);
        } catch (\RuntimeException) {
            return;
        }
        $this->jwksJson  = $data['jwks'];
        $this->fetchedAt = $fetchedAt;
    }

    private function writeCache(): void
    {
        $dir = dirname($this->cachePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // an unwritable cache only costs a refetch per minute
        }
        $tmp = $this->cachePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $out = json_encode(['fetched_at' => $this->fetchedAt, 'attempted_at' => $this->attemptedAt, 'jwks' => $this->jwksJson]);
        if ($out !== false && @file_put_contents($tmp, $out) !== false) {
            @rename($tmp, $this->cachePath);
        }
        @unlink($tmp);
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ??= LoggerFactory::create('api-error');
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
