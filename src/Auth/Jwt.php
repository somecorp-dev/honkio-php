<?php

declare(strict_types=1);

namespace Honkio\Auth;

use Honkio\Exception\TokenVerificationException;

/**
 * Compact JWS parsing and ES256 (ECDSA P-256 with SHA-256) checking on
 * ext-openssl. HonkIO Auth signs with ES256 only, so nothing else is
 * implemented and nothing else is accepted.
 *
 * @internal
 */
final class Jwt
{
    /** Longer than any token HonkIO issues; a bigger one is refused before any work. */
    public const MAX_LENGTH = 16384;

    /** SubjectPublicKeyInfo header for an uncompressed P-256 point (id-ecPublicKey, prime256v1). */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, signingInput: string, signature: string}
     *
     * @throws TokenVerificationException
     */
    public static function decode(string $jwt): array
    {
        if (strlen($jwt) > self::MAX_LENGTH) {
            throw new TokenVerificationException('too_large', 'The token is longer than any HonkIO issues.');
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new TokenVerificationException('malformed', 'The token is not a signed JWT (three dot-separated parts).');
        }
        [$h, $p, $s] = $parts;
        $header = self::json(self::b64urlDecode($h));
        $payload = self::json(self::b64urlDecode($p));
        $signature = self::b64urlDecode($s);
        if ($header === null || $payload === null || $signature === null) {
            throw new TokenVerificationException('malformed', 'The token\'s parts are not base64url JSON.');
        }

        return ['header' => $header, 'payload' => $payload, 'signingInput' => $h . '.' . $p, 'signature' => $signature];
    }

    /** Strict base64url without padding; null for anything else. */
    public static function b64urlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1 || strlen($value) % 4 === 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    public static function b64urlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** True when $signature (raw R || S, 64 bytes) is a valid ES256 signature of $signingInput under $pem. */
    public static function verifyEs256(string $signingInput, string $signature, string $pem): bool
    {
        $der = self::rawSignatureToDer($signature);
        if ($der === null) {
            return false;
        }
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }

        return openssl_verify($signingInput, $der, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * A PEM public key for an EC P-256 signing JWK, or null when the JWK is
     * anything else (another curve or key type, an encryption key, an alg
     * other than ES256, coordinates of the wrong size).
     *
     * @param array<mixed> $jwk
     */
    public static function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256') {
            return null;
        }
        if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
            return null;
        }
        if (isset($jwk['alg']) && $jwk['alg'] !== 'ES256') {
            return null;
        }
        $x = is_string($jwk['x'] ?? null) ? self::b64urlDecode($jwk['x']) : null;
        $y = is_string($jwk['y'] ?? null) ? self::b64urlDecode($jwk['y']) : null;
        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }
        $der = (string) hex2bin(self::P256_SPKI_PREFIX) . "\x04" . $x . $y;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";

        // openssl refuses a point that is not on the curve.
        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    /** JOSE's R || S (32 bytes each) as the DER SEQUENCE openssl expects. */
    public static function rawSignatureToDer(string $raw): ?string
    {
        if (strlen($raw) !== 64) {
            return null;
        }
        $int = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || ord($bytes[0]) >= 0x80) {
                $bytes = "\x00" . $bytes;
            }

            return "\x02" . pack('C', strlen($bytes)) . $bytes; // at most 33 bytes: a short-form length
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));

        return "\x30" . pack('C', strlen($body)) . $body;
    }

    /** @return array<string, mixed>|null a JSON object, or null */
    private static function json(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }
        try {
            $value = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || !str_starts_with(ltrim($text), '{')) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
