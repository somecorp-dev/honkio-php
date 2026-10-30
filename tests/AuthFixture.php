<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Auth\Cache;
use Honkio\Auth\HonkioAuth;
use Honkio\Auth\InMemoryCache;
use Honkio\Auth\Jwt;

/** A fake Honkio Auth issuer: a real P-256 key, its JWKS and discovery, and tokens signed with it. */
final class AuthFixture
{
    public const ISSUER = 'https://login.example.ca';
    public const CLIENT_ID = 'hka_client_1';

    public int $now = 1_800_000_000;
    public string $kid = 'key_1';
    public readonly \OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('openssl could not make a P-256 key');
        }
        $this->key = $key;
    }

    /** @return array<string, string> */
    public function jwk(?\OpenSSLAsymmetricKey $key = null, ?string $kid = null): array
    {
        $details = openssl_pkey_get_details($key ?? $this->key);
        assert(is_array($details));
        $pad = static fn (string $b): string => str_pad($b, 32, "\x00", STR_PAD_LEFT);

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'use' => 'sig',
            'alg' => 'ES256',
            'kid' => $kid ?? $this->kid,
            'x' => Jwt::b64urlEncode($pad($details['ec']['x'])),
            'y' => Jwt::b64urlEncode($pad($details['ec']['y'])),
        ];
    }

    /** @param list<array<string, string>>|null $keys */
    public function jwksResponse(?array $keys = null): Response
    {
        return MockHttp::json(200, ['keys' => $keys ?? [$this->jwk()]]);
    }

    /** @param array<string, mixed> $override */
    public function discoveryResponse(array $override = []): Response
    {
        $iss = self::ISSUER;

        return MockHttp::json(200, array_merge([
            'issuer' => $iss,
            'authorization_endpoint' => "$iss/authorize",
            'token_endpoint' => "$iss/token",
            'userinfo_endpoint' => "$iss/userinfo",
            'jwks_uri' => "$iss/.well-known/jwks.json",
            'end_session_endpoint' => "$iss/logout",
            'revocation_endpoint' => "$iss/revoke",
        ], $override));
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header
     */
    public function sign(array $claims, array $header = [], ?\OpenSSLAsymmetricKey $key = null): string
    {
        $header = array_merge(['alg' => 'ES256', 'kid' => $this->kid], $header);
        $input = Jwt::b64urlEncode(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . Jwt::b64urlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($input, $der, $key ?? $this->key, OPENSSL_ALGO_SHA256);

        return $input . '.' . Jwt::b64urlEncode(self::derToRaw($der));
    }

    /** @param array<string, mixed> $override */
    public function idToken(array $override = [], array $header = []): string
    {
        return $this->sign(array_merge([
            'iss' => self::ISSUER,
            'sub' => 'usr_1',
            'aud' => self::CLIENT_ID,
            'iat' => $this->now,
            'exp' => $this->now + 600,
            'sid' => 'ses_1',
            'amr' => ['otp'],
            'email' => 'ada@example.ca',
            'nonce' => 'n-1',
        ], $override), $header);
    }

    /** @param array<string, mixed> $override */
    public function accessToken(array $override = [], array $header = []): string
    {
        return $this->sign(array_merge([
            'iss' => self::ISSUER,
            'sub' => 'usr_1',
            'aud' => self::CLIENT_ID,
            'client_id' => self::CLIENT_ID,
            'scope' => 'openid email',
            'sid' => 'ses_1',
            'jti' => bin2hex(random_bytes(8)),
            'iat' => $this->now,
            'exp' => $this->now + 600,
        ], $override), array_merge(['typ' => 'at+jwt'], $header));
    }

    public function auth(MockHttp $mock, ?Cache $cache = null, ?string $secret = 'shh'): HonkioAuth
    {
        return new HonkioAuth([
            'issuer' => self::ISSUER,
            'client_id' => self::CLIENT_ID,
            'client_secret' => $secret,
            'http_client' => $mock->client,
            'cache' => $cache ?? new InMemoryCache(fn (): float => (float) $this->now),
            'clock' => fn (): int => $this->now,
        ]);
    }

    private static function derToRaw(string $der): string
    {
        $offset = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $len), "\x00");
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }
}
