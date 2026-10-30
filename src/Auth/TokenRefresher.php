<?php

declare(strict_types=1);

namespace Honkio\Auth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\IssuerUnavailableException;
use Honkio\Exception\TokenVerificationException;
use Honkio\Honkio;

/**
 * The outage-tolerant refresh rule: a session survives a Honkio outage, never
 * a refusal.
 *
 * - While the access token is unexpired, nothing is sent.
 * - Once it expires, the refresh token is exchanged. New tokens are checked
 *   and returned.
 * - If Honkio can't be reached (a connection error, a timeout, a 5xx, a 429,
 *   an unreadable answer), the session is kept on the old tokens until 24
 *   hours past the access token's expiry, then ended.
 * - If Honkio refuses the refresh (any other 4xx: revoked, blocked, deleted,
 *   removed from the organization, a reused refresh token), it ends at once.
 *
 * Refresh tokens rotate and a reused one revokes the whole session, so two
 * requests must never refresh the same token at the same time. The refresher
 * remembers each refresh's result for a minute in the cache under a hash of
 * the old refresh token, and hands it to a second request that arrives with
 * the same old token; with a cache shared across processes, also hold a lock
 * per session around refresh() (the Laravel package does).
 */
final class TokenRefresher
{
    public const OUTAGE_GRACE_SECONDS = 86400;
    public const EXPIRY_SKEW_SECONDS = 30;
    public const DEDUPE_SECONDS = 60;

    /** Statuses that mean Honkio is not answering right now, as opposed to refusing. */
    private const UNREACHABLE_STATUSES = [408, 429];

    /** @param \Closure(): int $clock */
    public function __construct(
        private readonly Discovery $discovery,
        private readonly TokenVerifier $verifier,
        private readonly ClientInterface $http,
        private readonly Cache $cache,
        private readonly string $clientId,
        private readonly ?string $clientSecret,
        private readonly \Closure $clock,
        private readonly float $timeout = 10.0,
    ) {
    }

    public function refresh(TokenSet $tokens): RefreshResult
    {
        $now = ($this->clock)();
        if (!$tokens->isExpired($now, self::EXPIRY_SKEW_SECONDS)) {
            return RefreshResult::valid($tokens);
        }
        if ($tokens->refreshToken === null) {
            return RefreshResult::ended('no_refresh_token');
        }

        $dedupeKey = 'honkio-auth:refreshed:' . hash('sha256', $tokens->refreshToken);
        $already = $this->cache->get($dedupeKey);
        if ($already !== null) {
            try {
                return RefreshResult::refreshed(TokenSet::fromArray($already));
            } catch (InvalidArgumentException) {
                // not ours; refresh as usual
            }
        }

        try {
            $tokenEndpoint = (string) $this->discovery->get()['token_endpoint'];
        } catch (IssuerUnavailableException) {
            return $this->unreachable($tokens, $now);
        }

        $headers = ['Accept' => 'application/json', 'User-Agent' => 'honkio-php/' . Honkio::VERSION];
        $form = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens->refreshToken];
        if ($this->clientSecret !== null && $this->clientSecret !== '') {
            // client_secret_basic: each part form-encoded, then base64 (RFC 6749 2.3.1).
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($this->clientId) . ':' . urlencode($this->clientSecret));
        } else {
            $form['client_id'] = $this->clientId;
        }

        try {
            $response = $this->http->request('POST', $tokenEndpoint, [
                'headers' => $headers,
                'form_params' => $form,
                'http_errors' => false,
                'allow_redirects' => false,
                'timeout' => $this->timeout,
                'connect_timeout' => min($this->timeout, 5.0),
            ]);
        } catch (GuzzleException) {
            return $this->unreachable($tokens, $now);
        }

        $status = $response->getStatusCode();
        $body = null;
        try {
            $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }

        if ($status >= 500 || in_array($status, self::UNREACHABLE_STATUSES, true)) {
            return $this->unreachable($tokens, $now);
        }
        if ($status < 200 || $status >= 300) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;

            return RefreshResult::ended('rejected', $error);
        }
        // A 2xx we can't read is a broken proxy or Honkio, not a refusal. If
        // the refresh token was in fact spent, the next try is refused.
        if (!is_array($body) || !is_string($body['access_token'] ?? null) || $body['access_token'] === '') {
            return $this->unreachable($tokens, $now);
        }

        $result = $this->fromResponse($body, $tokens);
        if ($result->changed() && $result->tokens !== null) {
            $this->cache->set($dedupeKey, $result->tokens->toArray(), self::DEDUPE_SECONDS);
        }

        return $result;
    }

    /** @param array<mixed> $body */
    private function fromResponse(array $body, TokenSet $old): RefreshResult
    {
        $access = (string) $body['access_token'];
        $idToken = is_string($body['id_token'] ?? null) && $body['id_token'] !== '' ? $body['id_token'] : null;
        try {
            try {
                $accessClaims = $this->verifier->verifyAccessToken($access);
                $idClaims = $idToken !== null ? $this->verifier->verifyIdToken($idToken) : null;
            } catch (IssuerUnavailableException) {
                // Straight from the token endpoint over TLS; the keys are what's unreachable.
                $accessClaims = $this->verifier->checkFromTokenEndpoint($access, true);
                $idClaims = $idToken !== null ? $this->verifier->checkFromTokenEndpoint($idToken, false) : null;
            }
        } catch (TokenVerificationException) {
            return RefreshResult::ended('invalid_response');
        }

        $subject = (string) ($idClaims['sub'] ?? $accessClaims['sub']);
        if ($subject !== (string) $accessClaims['sub'] || ($old->subject !== null && $old->subject !== $subject)) {
            return RefreshResult::ended('subject_changed');
        }

        $refresh = is_string($body['refresh_token'] ?? null) && $body['refresh_token'] !== '' ? $body['refresh_token'] : null;
        $scope = is_string($body['scope'] ?? null) ? $body['scope'] : $old->scope;

        return RefreshResult::refreshed(new TokenSet(
            $access,
            $refresh,
            $idToken ?? $old->idToken,
            (int) $accessClaims['exp'],
            $subject,
            $scope,
        ));
    }

    private function unreachable(TokenSet $tokens, int $now): RefreshResult
    {
        return $now <= $tokens->expiresAt + self::OUTAGE_GRACE_SECONDS
            ? RefreshResult::kept($tokens)
            : RefreshResult::ended('outage_limit');
    }
}
