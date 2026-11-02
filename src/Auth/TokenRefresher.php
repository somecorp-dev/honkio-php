<?php

declare(strict_types=1);

namespace Honkio\Auth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Honkio\Exception\ApiException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\IssuerUnavailableException;
use Honkio\Exception\TokenVerificationException;
use Honkio\Honkio;

/**
 * The outage-tolerant refresh rule: a session survives a HonkIO outage, never
 * a refusal.
 *
 * - While the access token is unexpired, nothing is sent.
 * - Once it expires, the refresh token is exchanged. New tokens are checked
 *   and returned.
 * - If HonkIO can't be reached (a connection error, a timeout, a 5xx, an
 *   unreadable answer), the session is kept on the old tokens until 24 hours
 *   past the access token's expiry, then ended.
 * - If HonkIO answers 429 (shedding load) or 408, it has neither refreshed nor
 *   refused: the old tokens are kept for this request only, without the
 *   outage treatment ({@see RefreshResult::busy()}), and the next request
 *   tries again. Never past the same 24-hour limit.
 * - If HonkIO refuses the refresh (any other 4xx: revoked, blocked, deleted,
 *   removed from the organization, a reused refresh token), it ends at once.
 * - Once {@see OutageBreaker::TRIP_SESSIONS} different sessions in a row
 *   could not reach this app's issuer, refreshes for this app keep their
 *   sessions without calling HonkIO for {@see OutageBreaker::PAUSE_SECONDS}
 *   ({@see OutageBreaker}; the pause is per app, kept in the cache).
 *
 * Refresh tokens rotate and a reused one revokes the whole session, so two
 * requests must never refresh the same token at the same time. The refresher
 * remembers each refresh's result for a few seconds ({@see DEDUPE_SECONDS}) in
 * the cache under a hash of the old refresh token, and hands it to a request
 * that arrives with the same old token in that window; with a cache shared
 * across processes, also hold a lock per session around refresh(). Turn the
 * memo off (the `remember_refreshes` option of {@see HonkioAuth}) when you
 * serialise refreshes per session yourself, as the Laravel package does.
 */
final class TokenRefresher
{
    public const OUTAGE_GRACE_SECONDS = 86400;
    public const EXPIRY_SKEW_SECONDS = 30;
    /** How long a refresh's result is handed to requests that arrive with the same old refresh token. */
    public const DEDUPE_SECONDS = 2;

    /** HonkIO answered but would not handle the refresh now: neither an outage nor a refusal. */
    private const BUSY_STATUSES = [408, 429];

    /** The memo: a hash of the old refresh token => the tokens its refresh returned. */
    private const MEMO_PREFIX = 'honkio-auth:refreshed:';
    /** A hash of the new refresh token => the hash of the one it replaced, so revoke() finds the memo entry that hands it out. */
    private const MEMO_FROM_PREFIX = 'honkio-auth:refreshed-from:';

    /** @param \Closure(): int $clock */
    public function __construct(
        private readonly Discovery $discovery,
        private readonly TokenVerifier $verifier,
        private readonly ClientInterface $http,
        private readonly Cache $cache,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly ?string $clientSecret,
        private readonly \Closure $clock,
        private readonly float $timeout = 10.0,
        private readonly bool $remember = true,
        private readonly ?OutageBreaker $breaker = null,
    ) {
    }

    /**
     * var_dump and print_r show the client, never the client secret or the
     * cache (which holds refreshed tokens).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret === null ? null : '[redacted]',
            'timeout' => $this->timeout,
            'remember' => $this->remember,
        ];
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

        $session = hash('sha256', $tokens->refreshToken);
        $dedupeKey = self::MEMO_PREFIX . $session;
        $already = $this->remember ? $this->cache->get($dedupeKey) : null;
        if ($already !== null) {
            try {
                return RefreshResult::refreshed(TokenSet::fromArray($already));
            } catch (InvalidArgumentException) {
                // not ours (or a revoked entry); refresh as usual
            }
        }

        // This app's issuer looks down: keep the session without waiting on it.
        if ($this->breaker?->paused() === true) {
            return $this->unreachable($tokens, $now);
        }

        try {
            $tokenEndpoint = (string) $this->discovery->get()['token_endpoint'];
        } catch (IssuerUnavailableException) {
            return $this->down($tokens, $now, $session);
        }

        try {
            $response = $this->post($tokenEndpoint, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens->refreshToken]);
        } catch (GuzzleException) {
            return $this->down($tokens, $now, $session);
        }

        $status = $response->getStatusCode();
        $body = self::decode($response->getBody()->__toString());

        if ($status >= 500) {
            return $this->down($tokens, $now, $session);
        }
        // A 2xx we can't read is a broken proxy or Honkio, not a refusal. If
        // the refresh token was in fact spent, the next try is refused.
        if ($status >= 200 && $status < 300 && (!is_array($body) || !is_string($body['access_token'] ?? null) || $body['access_token'] === '')) {
            return $this->down($tokens, $now, $session);
        }
        // Honkio answered: whatever it said, it is up.
        $this->breaker?->answered();
        if (in_array($status, self::BUSY_STATUSES, true)) {
            return $this->withinLimit($tokens, $now) ? RefreshResult::busy($tokens) : RefreshResult::ended('outage_limit');
        }
        if ($status < 200 || $status >= 300) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;

            return RefreshResult::ended('rejected', $error);
        }

        $result = $this->fromResponse($body, $tokens);
        if ($this->remember && $result->changed() && $result->tokens !== null) {
            $this->cache->set($dedupeKey, $result->tokens->toArray(), self::DEDUPE_SECONDS);
            if ($result->tokens->refreshToken !== null) {
                // So revoke() of the new refresh token also forgets this entry.
                $this->cache->set(self::MEMO_FROM_PREFIX . hash('sha256', $result->tokens->refreshToken), ['from' => $session], self::DEDUPE_SECONDS);
            }
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

    /**
     * Ends the session at HonkIO (RFC 7009, for sign-out) and first forgets
     * the refresh memo for it: the entry under this refresh token, and the
     * one that hands this token to a request still holding the token it
     * replaced. Succeeds whether or not HonkIO knew the token.
     *
     * @throws InvalidArgumentException for an empty token
     * @throws IssuerUnavailableException when HonkIO can't be reached, or answers 5xx, 408 or 429
     * @throws ApiException when HonkIO refuses (401 invalid_client: check the client id and secret)
     */
    public function revoke(string $refreshToken): void
    {
        if ($refreshToken === '') {
            throw new InvalidArgumentException('revoke() needs the refresh token to revoke.');
        }
        $this->forget($refreshToken);

        $config = $this->discovery->get();
        $endpoint = is_string($config['revocation_endpoint'] ?? null) ? $config['revocation_endpoint'] : $this->discovery->issuer() . '/revoke';
        try {
            $response = $this->post($endpoint, ['token' => $refreshToken, 'token_type_hint' => 'refresh_token']);
        } catch (GuzzleException $e) {
            throw new IssuerUnavailableException('Could not reach the revocation endpoint: ' . $e->getMessage(), 0, $e);
        }
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }
        if ($status >= 500 || in_array($status, self::BUSY_STATUSES, true)) {
            throw new IssuerUnavailableException("The revocation endpoint answered HTTP $status.");
        }
        $body = self::decode($response->getBody()->__toString());
        $error = is_array($body) && is_string($body['error'] ?? null) && $body['error'] !== '' ? $body['error'] : 'revoke_failed';
        $description = is_array($body) && is_string($body['error_description'] ?? null) ? $body['error_description'] : "HonkIO refused to revoke the token (HTTP $status).";

        throw ApiException::create($error, $description, $status, $body);
    }

    /** Forgets the refresh memo for this refresh token (see {@see revoke()}). */
    public function forget(string $refreshToken): void
    {
        $session = hash('sha256', $refreshToken);
        $from = $this->cache->get(self::MEMO_FROM_PREFIX . $session);
        $keys = [self::MEMO_PREFIX . $session, self::MEMO_FROM_PREFIX . $session];
        if (is_string($from['from'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $from['from']) === 1) {
            $keys[] = self::MEMO_PREFIX . $from['from'];
        }
        foreach ($keys as $key) {
            if ($this->cache instanceof DeletableCache) {
                $this->cache->delete($key);
            } else {
                // A cache without delete(): an empty value that expires at once reads as missing.
                $this->cache->set($key, [], 0);
            }
        }
    }

    /**
     * POSTs a form to one of the issuer's endpoints with this client's
     * authentication: client_secret_basic for a confidential client, the
     * client id in the body for a public one.
     *
     * @param array<string, string> $form
     *
     * @throws GuzzleException
     */
    private function post(string $url, array $form): \Psr\Http\Message\ResponseInterface
    {
        $headers = ['Accept' => 'application/json', 'User-Agent' => 'honkio-php/' . Honkio::VERSION];
        if ($this->clientSecret !== null && $this->clientSecret !== '') {
            // client_secret_basic: each part form-encoded, then base64 (RFC 6749 2.3.1).
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($this->clientId) . ':' . urlencode($this->clientSecret));
        } else {
            $form['client_id'] = $this->clientId;
        }

        return $this->http->request('POST', $url, [
            'headers' => $headers,
            'form_params' => $form,
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => min($this->timeout, 5.0),
        ]);
    }

    private static function decode(string $raw): mixed
    {
        try {
            return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /** HonkIO could not be reached for this session: count it toward the breaker, keep the session. */
    private function down(TokenSet $tokens, int $now, string $session): RefreshResult
    {
        $this->breaker?->unreachable($session);

        return $this->unreachable($tokens, $now);
    }

    private function unreachable(TokenSet $tokens, int $now): RefreshResult
    {
        return $this->withinLimit($tokens, $now) ? RefreshResult::kept($tokens) : RefreshResult::ended('outage_limit');
    }

    /** A session never runs on tokens HonkIO hasn't refreshed for more than 24 hours past their expiry. */
    private function withinLimit(TokenSet $tokens, int $now): bool
    {
        return $now <= $tokens->expiresAt + self::OUTAGE_GRACE_SECONDS;
    }
}
