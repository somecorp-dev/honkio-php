<?php

declare(strict_types=1);

namespace Honkio\Auth;

use GuzzleHttp\ClientInterface;
use Honkio\Exception\IssuerUnavailableException;

/**
 * The app's OpenID configuration ({issuer}/.well-known/openid-configuration).
 * A fetched copy is used for an hour; an older one is still used while the
 * issuer can't be reached, for up to a week.
 */
final class Discovery
{
    public const FRESH_SECONDS = 3600;
    public const KEEP_SECONDS = 7 * 86400;

    private const REQUIRED = ['authorization_endpoint', 'token_endpoint', 'jwks_uri'];
    private const OPTIONAL = ['userinfo_endpoint', 'end_session_endpoint', 'revocation_endpoint'];

    /** @param \Closure(): int $clock */
    public function __construct(
        private readonly string $issuer,
        private readonly ClientInterface $http,
        private readonly Cache $cache,
        private readonly \Closure $clock,
        private readonly float $timeout = 5.0,
        private readonly ?OutageBreaker $breaker = null,
    ) {
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    /**
     * The configuration document, checked: its issuer is exactly this one and
     * every endpoint is https.
     *
     * @return array<string, mixed>
     *
     * @throws IssuerUnavailableException when it can't be fetched and nothing is cached
     */
    public function get(): array
    {
        $key = 'honkio-auth:discovery:' . hash('sha256', $this->issuer);
        $cached = $this->cache->get($key);
        $now = ($this->clock)();
        $doc = is_array($cached['doc'] ?? null) ? $cached['doc'] : null;
        $fetchedAt = is_int($cached['fetched_at'] ?? null) ? $cached['fetched_at'] : 0;
        // While the issuer looks down (see OutageBreaker), an older copy is used without waiting on it.
        if ($doc !== null && ($now - $fetchedAt < self::FRESH_SECONDS || $this->breaker?->paused() === true)) {
            /** @var array<string, mixed> $doc */
            return $doc;
        }
        try {
            $fresh = $this->check(Fetch::json($this->http, $this->issuer . '/.well-known/openid-configuration', $this->timeout));
        } catch (IssuerUnavailableException $e) {
            if ($doc !== null) {
                /** @var array<string, mixed> $doc */
                return $doc;
            }
            throw $e;
        }
        $this->cache->set($key, ['doc' => $fresh, 'fetched_at' => $now], self::KEEP_SECONDS);

        return $fresh;
    }

    /**
     * @param array<mixed> $doc
     * @return array<string, mixed>
     *
     * @throws IssuerUnavailableException
     */
    private function check(array $doc): array
    {
        if (($doc['issuer'] ?? null) !== $this->issuer) {
            throw new IssuerUnavailableException(sprintf(
                'The discovery document at %s names another issuer; check the issuer setting (the app\'s https://hostname, no trailing slash).',
                $this->issuer,
            ));
        }
        foreach (self::REQUIRED as $field) {
            if (!Fetch::isSecureUrl($doc[$field] ?? null)) {
                throw new IssuerUnavailableException("The discovery document has no https $field.");
            }
        }
        foreach (self::OPTIONAL as $field) {
            if (isset($doc[$field]) && !Fetch::isSecureUrl($doc[$field])) {
                throw new IssuerUnavailableException("The discovery document's $field is not https.");
            }
        }

        /** @var array<string, mixed> $doc */
        return $doc;
    }
}
