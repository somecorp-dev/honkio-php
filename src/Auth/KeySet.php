<?php

declare(strict_types=1);

namespace Honkio\Auth;

use GuzzleHttp\ClientInterface;
use Honkio\Exception\IssuerUnavailableException;
use Honkio\Exception\TokenVerificationException;

/**
 * The app's signing keys (its JWKS), cached. Fetched keys are used for five
 * minutes, then refetched; while the issuer can't be reached, the cached keys
 * keep working for up to a week, so signed-in users stay signed in through an
 * outage. A token naming a key that isn't cached triggers a refetch (keys
 * rotate), at most once a minute.
 */
final class KeySet
{
    public const FRESH_SECONDS = 300;
    public const KEEP_SECONDS = 7 * 86400;
    public const REFETCH_MIN_SECONDS = 60;

    /** @param \Closure(): int $clock */
    public function __construct(
        private readonly Discovery $discovery,
        private readonly ClientInterface $http,
        private readonly Cache $cache,
        private readonly \Closure $clock,
        private readonly float $timeout = 5.0,
    ) {
    }

    /**
     * The PEM public key for $kid.
     *
     * @throws TokenVerificationException unknown_key when the issuer does not publish it
     * @throws IssuerUnavailableException when it isn't cached and the keys can't be fetched
     */
    public function publicKeyPem(string $kid): string
    {
        $key = 'honkio-auth:jwks:' . hash('sha256', $this->discovery->issuer());
        $now = ($this->clock)();
        $cached = $this->cache->get($key);
        $keys = is_array($cached['keys'] ?? null) ? $cached['keys'] : null;
        $fetchedAt = is_int($cached['fetched_at'] ?? null) ? $cached['fetched_at'] : 0;
        $found = $keys !== null ? self::find($keys, $kid) : null;

        if ($found !== null && $now - $fetchedAt < self::FRESH_SECONDS) {
            return $found;
        }
        if ($keys !== null && $found === null && $now - $fetchedAt < self::REFETCH_MIN_SECONDS) {
            throw new TokenVerificationException('unknown_key', 'The token was signed with a key the issuer does not publish.');
        }

        try {
            $fresh = $this->fetch();
        } catch (IssuerUnavailableException $e) {
            if ($found !== null) {
                return $found; // stale, but the issuer is down: keep signed-in users signed in
            }
            throw $e;
        }
        $this->cache->set($key, ['keys' => $fresh, 'fetched_at' => $now], self::KEEP_SECONDS);
        $pem = self::find($fresh, $kid);
        if ($pem === null) {
            throw new TokenVerificationException('unknown_key', 'The token was signed with a key the issuer does not publish.');
        }

        return $pem;
    }

    /**
     * @return array<string, string> kid => PEM, only the ES256 P-256 signing keys
     *
     * @throws IssuerUnavailableException
     */
    private function fetch(): array
    {
        $uri = $this->discovery->get()['jwks_uri'];
        $jwks = Fetch::json($this->http, (string) (is_string($uri) ? $uri : ''), $this->timeout);
        if (!isset($jwks['keys']) || !is_array($jwks['keys']) || !array_is_list($jwks['keys'])) {
            throw new IssuerUnavailableException('The JWKS document has no keys list.');
        }
        $keys = [];
        foreach ($jwks['keys'] as $jwk) {
            if (!is_array($jwk) || !is_string($jwk['kid'] ?? null) || $jwk['kid'] === '') {
                continue;
            }
            $pem = Jwt::jwkToPem($jwk);
            if ($pem !== null) {
                $keys[$jwk['kid']] = $pem;
            }
        }

        return $keys;
    }

    /** @param array<mixed> $keys */
    private static function find(array $keys, string $kid): ?string
    {
        $pem = $keys[$kid] ?? null;

        return is_string($pem) ? $pem : null;
    }
}
