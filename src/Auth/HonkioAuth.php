<?php

declare(strict_types=1);

namespace Honkio\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\IssuerUnavailableException;
use Honkio\Exception\TokenVerificationException;

/**
 * HonkIO Auth for one app (not available yet): check its tokens and keep its
 * sessions alive through a HonkIO outage. Sign-in itself is standard OpenID
 * Connect (authorization code with PKCE) against the app's issuer, so any OIDC
 * client can run it; on Laravel, honkio/laravel ships a Socialite provider.
 *
 * ```php
 * $auth = new Honkio\Auth\HonkioAuth([
 *     'issuer' => 'https://login.example.ca',   // the app's hostname
 *     'client_id' => getenv('HONKIO_AUTH_CLIENT_ID'),
 *     'client_secret' => getenv('HONKIO_AUTH_CLIENT_SECRET'),
 *     'cache' => $sharedCache,                  // a Honkio\Auth\Cache shared across requests
 * ]);
 * $claims = $auth->verifyAccessToken($bearerToken);
 * $result = $auth->refresh(TokenSet::fromArray($_SESSION['honkio']));
 * $auth->revoke($refreshToken); // sign-out
 * ```
 */
final class HonkioAuth
{
    private readonly string $issuer;
    private readonly string $clientId;
    private readonly Discovery $discovery;
    private readonly TokenVerifier $verifier;
    private readonly TokenRefresher $refresher;

    /**
     * @param array{issuer?: ?string, client_id?: ?string, client_secret?: ?string, http_client?: ClientInterface, cache?: Cache, leeway?: int, timeout?: float|int, clock?: \Closure(): int, remember_refreshes?: bool} $options
     *        issuer is the app's `https://hostname` (no path, no trailing slash
     *        needed); client_secret is for a confidential app (leave it out
     *        for a public one); cache defaults to one that lasts this process;
     *        leeway (seconds, default 60) allows for clock skew; timeout
     *        (seconds, default 10) bounds a refresh, and discovery and key
     *        fetches use at most 5; remember_refreshes (default true) keeps
     *        each refresh's result in the cache for a few seconds, for a
     *        request that arrives with the same old refresh token (turn it
     *        off when you serialise refreshes per session yourself).
     *
     * @throws InvalidArgumentException for a missing or non-https issuer, a missing client id, or no openssl extension
     */
    public function __construct(array $options)
    {
        if (!extension_loaded('openssl')) {
            throw new InvalidArgumentException('HonkIO Auth token checks need the PHP openssl extension.');
        }
        $issuer = rtrim((string) ($options['issuer'] ?? ''), '/');
        if (!Fetch::isSecureUrl($issuer) || (parse_url($issuer, PHP_URL_PATH) ?? '') !== '' || str_contains($issuer, '?') || str_contains($issuer, '#')) {
            throw new InvalidArgumentException('The issuer must be the app\'s https://hostname, such as https://login.example.ca.');
        }
        $clientId = (string) ($options['client_id'] ?? '');
        if ($clientId === '') {
            throw new InvalidArgumentException('Missing client_id: copy it from the app in the dashboard.');
        }
        $leeway = (int) ($options['leeway'] ?? 60);
        if ($leeway < 0 || $leeway > 300) {
            throw new InvalidArgumentException('leeway must be from 0 to 300 seconds.');
        }
        $secret = $options['client_secret'] ?? null;
        $http = $options['http_client'] ?? new Client();
        $cache = $options['cache'] ?? new InMemoryCache();
        $clock = $options['clock'] ?? static fn (): int => time();
        $timeout = (float) ($options['timeout'] ?? 10.0);
        $fetchTimeout = min($timeout, 5.0);

        $this->issuer = $issuer;
        $this->clientId = $clientId;
        // The outage pause is this app's alone: keyed by its issuer and client id in the shared cache.
        $breaker = new OutageBreaker($cache, $issuer, $clientId, $clock);
        $this->discovery = new Discovery($issuer, $http, $cache, $clock, $fetchTimeout, $breaker);
        $keys = new KeySet($this->discovery, $http, $cache, $clock, $fetchTimeout, $breaker);
        $this->verifier = new TokenVerifier($issuer, $clientId, $keys, $clock, $leeway);
        $this->refresher = new TokenRefresher(
            $this->discovery,
            $this->verifier,
            $http,
            $cache,
            $clientId,
            is_string($secret) && $secret !== '' ? $secret : null,
            $clock,
            $timeout,
            (bool) ($options['remember_refreshes'] ?? true),
            $breaker,
        );
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    /**
     * The app's OpenID configuration (endpoints), cached.
     *
     * @return array<string, mixed>
     *
     * @throws IssuerUnavailableException
     */
    public function configuration(): array
    {
        return $this->discovery->get();
    }

    /**
     * See {@see TokenVerifier::verifyIdToken()}.
     *
     * @return array<string, mixed>
     *
     * @throws TokenVerificationException
     * @throws IssuerUnavailableException
     */
    public function verifyIdToken(string $jwt, ?string $nonce = null): array
    {
        return $this->verifier->verifyIdToken($jwt, $nonce);
    }

    /**
     * See {@see TokenVerifier::verifyAccessToken()}.
     *
     * @return array<string, mixed>
     *
     * @throws TokenVerificationException
     * @throws IssuerUnavailableException
     */
    public function verifyAccessToken(string $jwt): array
    {
        return $this->verifier->verifyAccessToken($jwt);
    }

    /**
     * Applies the outage-tolerant refresh rule; see {@see TokenRefresher}.
     */
    public function refresh(TokenSet $tokens): RefreshResult
    {
        return $this->refresher->refresh($tokens);
    }

    /**
     * Ends the session at HonkIO, for sign-out: revokes the refresh token at
     * the app's revocation endpoint (RFC 7009), so it and the session's
     * access tokens stop being refreshed. Before calling HonkIO it deletes
     * the refresh memo for this token from the cache, so a request still
     * carrying the token this one replaced is not handed it. Succeeds whether
     * or not HonkIO knew the token.
     *
     * @throws InvalidArgumentException for an empty token
     * @throws IssuerUnavailableException when HonkIO can't be reached, or answers 5xx, 408 or 429 (the memo is forgotten anyway)
     * @throws \Honkio\Exception\ApiException when HonkIO refuses the client (401 invalid_client)
     */
    public function revoke(string $refreshToken): void
    {
        $this->refresher->revoke($refreshToken);
    }

    /**
     * Checks the token endpoint's answer to an authorization code (the ID
     * token with the nonce you sent, and the access token) and returns the
     * token set to keep in the session.
     *
     * @param array<mixed> $response the decoded JSON from the token endpoint
     *
     * @throws TokenVerificationException
     * @throws IssuerUnavailableException
     */
    public function tokenSetFromResponse(array $response, ?string $nonce): TokenSet
    {
        $access = $response['access_token'] ?? null;
        $idToken = $response['id_token'] ?? null;
        if (!is_string($access) || $access === '' || !is_string($idToken) || $idToken === '') {
            throw new TokenVerificationException('malformed', 'The token response has no access token or ID token (is the openid scope requested?).');
        }
        $idClaims = $this->verifier->verifyIdToken($idToken, $nonce);
        $accessClaims = $this->verifier->verifyAccessToken($access);
        if ($idClaims['sub'] !== $accessClaims['sub']) {
            throw new TokenVerificationException('missing_subject', 'The ID token and access token name different users.');
        }
        $refresh = $response['refresh_token'] ?? null;

        return new TokenSet(
            $access,
            is_string($refresh) && $refresh !== '' ? $refresh : null,
            $idToken,
            (int) $accessClaims['exp'],
            (string) $idClaims['sub'],
            is_string($response['scope'] ?? null) ? $response['scope'] : '',
        );
    }

    /**
     * The RP-initiated logout URL to send the browser to after ending your own
     * session, so the user is signed out of the hosted page too.
     *
     * @throws IssuerUnavailableException
     */
    public function logoutUrl(?string $idTokenHint = null, ?string $postLogoutRedirectUri = null, ?string $state = null): string
    {
        $endpoint = $this->discovery->get()['end_session_endpoint'] ?? null;
        $url = is_string($endpoint) ? $endpoint : $this->issuer . '/logout';
        $query = array_filter([
            'client_id' => $this->clientId,
            'id_token_hint' => $idTokenHint,
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
            'state' => $state,
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
