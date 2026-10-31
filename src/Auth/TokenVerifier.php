<?php

declare(strict_types=1);

namespace Honkio\Auth;

use Honkio\Exception\IssuerUnavailableException;
use Honkio\Exception\TokenVerificationException;

/**
 * Checks HonkIO Auth ID and access tokens for one app: an ES256 signature by
 * one of the app's published keys, the app's exact issuer, the app's client
 * id as audience, the token type, and the time claims (with $leeway seconds
 * for clock skew). A token for another app, even one on your own account,
 * never passes.
 */
final class TokenVerifier
{
    private const ID = 'id';
    private const ACCESS = 'access';

    /** @param \Closure(): int $clock */
    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly KeySet $keys,
        private readonly \Closure $clock,
        private readonly int $leeway = 60,
    ) {
    }

    /**
     * Checks an ID token and returns its claims (`sub`, `email`, `amr`, `sid`,
     * `org_id`, ...). Pass the nonce you sent to /authorize; it must match.
     *
     * @return array<string, mixed>
     *
     * @throws TokenVerificationException
     * @throws IssuerUnavailableException when the signing key isn't cached and can't be fetched
     */
    public function verifyIdToken(string $jwt, ?string $nonce = null): array
    {
        return $this->verify($jwt, self::ID, $nonce, true);
    }

    /**
     * Checks an access token (`typ: at+jwt`) presented to your API and returns
     * its claims (`sub`, `sid`, `scope`, `client_id`, ...). It proves the
     * token was issued and is unexpired; a session revoked since then is
     * caught at the next refresh (access tokens last at most 10 minutes).
     *
     * @return array<string, mixed>
     *
     * @throws TokenVerificationException
     * @throws IssuerUnavailableException when the signing key isn't cached and can't be fetched
     */
    public function verifyAccessToken(string $jwt): array
    {
        return $this->verify($jwt, self::ACCESS, null, true);
    }

    /**
     * Every check except the signature, for tokens that arrived directly from
     * the token endpoint over TLS (OpenID Connect Core 3.1.3.7) while the keys
     * could not be fetched.
     *
     * @return array<string, mixed>
     *
     * @throws TokenVerificationException
     *
     * @internal
     */
    public function checkFromTokenEndpoint(string $jwt, bool $access): array
    {
        return $this->verify($jwt, $access ? self::ACCESS : self::ID, null, false);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TokenVerificationException
     * @throws IssuerUnavailableException
     */
    private function verify(string $jwt, string $kind, ?string $nonce, bool $checkSignature): array
    {
        ['header' => $header, 'payload' => $claims, 'signingInput' => $input, 'signature' => $signature] = Jwt::decode($jwt);

        if (($header['alg'] ?? null) !== 'ES256') {
            throw new TokenVerificationException('unsupported_algorithm', 'Only ES256 tokens are accepted.');
        }
        if (array_key_exists('crit', $header)) {
            throw new TokenVerificationException('malformed', 'The token has critical header parameters this library does not understand.');
        }
        $typ = $header['typ'] ?? null;
        if ($kind === self::ACCESS) {
            if (!is_string($typ) || !in_array(strtolower($typ), ['at+jwt', 'application/at+jwt'], true)) {
                throw new TokenVerificationException('wrong_type', 'This is not an access token (typ at+jwt).');
            }
        } elseif ($typ !== null && (!is_string($typ) || strtoupper($typ) !== 'JWT')) {
            throw new TokenVerificationException('wrong_type', 'This is not an ID token.');
        }

        if ($checkSignature) {
            $kid = $header['kid'] ?? null;
            if (!is_string($kid) || $kid === '') {
                throw new TokenVerificationException('unknown_key', 'The token names no signing key.');
            }
            if (!Jwt::verifyEs256($input, $signature, $this->keys->publicKeyPem($kid))) {
                throw new TokenVerificationException('bad_signature', 'The token\'s signature does not match.');
            }
        }

        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new TokenVerificationException('wrong_issuer', 'The token was issued by another issuer.');
        }
        $this->checkAudience($claims, $kind);

        $now = ($this->clock)();
        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) && !is_float($exp)) {
            throw new TokenVerificationException('malformed', 'The token has no expiry.');
        }
        if ($now - $this->leeway >= $exp) {
            throw new TokenVerificationException('expired', 'The token has expired.');
        }
        $iat = $claims['iat'] ?? null;
        if (!is_int($iat) && !is_float($iat)) {
            throw new TokenVerificationException('malformed', 'The token has no issue time.');
        }
        if ($iat > $now + $this->leeway) {
            throw new TokenVerificationException('not_yet_valid', 'The token was issued in the future; check the server clock.');
        }
        $nbf = $claims['nbf'] ?? null;
        if ($nbf !== null && (!(is_int($nbf) || is_float($nbf)) || $nbf > $now + $this->leeway)) {
            throw new TokenVerificationException('not_yet_valid', 'The token is not valid yet.');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new TokenVerificationException('missing_subject', 'The token names no user.');
        }

        if ($nonce !== null) {
            $got = $claims['nonce'] ?? null;
            if (!is_string($got) || !hash_equals($nonce, $got)) {
                throw new TokenVerificationException('nonce_mismatch', 'The ID token\'s nonce is not the one this sign-in sent.');
            }
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @throws TokenVerificationException
     */
    private function checkAudience(array $claims, string $kind): void
    {
        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) && array_is_list($aud) ? $aud : []);
        if (!in_array($this->clientId, $audiences, true)) {
            throw new TokenVerificationException('wrong_audience', 'The token was issued to another app.');
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId) {
            throw new TokenVerificationException('wrong_audience', 'The token has several audiences and was not issued to this app.');
        }
        if ($kind === self::ACCESS && isset($claims['client_id']) && $claims['client_id'] !== $this->clientId) {
            throw new TokenVerificationException('wrong_audience', 'The access token was issued to another app.');
        }
    }
}
