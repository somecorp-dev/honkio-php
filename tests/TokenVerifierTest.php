<?php

declare(strict_types=1);

namespace Honkio\Tests;

use Honkio\Auth\HonkioAuth;
use Honkio\Auth\Jwt;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\TokenVerificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ID and access token checks, against real ES256 signatures. */
final class TokenVerifierTest extends TestCase
{
    private AuthFixture $f;

    protected function setUp(): void
    {
        $this->f = new AuthFixture();
    }

    /** @return array{HonkioAuth, MockHttp} */
    private function auth(): array
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->f->jwksResponse()]);

        return [$this->f->auth($mock), $mock];
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("expected $reason");
        } catch (TokenVerificationException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function testAValidIdTokenPassesAndReturnsItsClaims(): void
    {
        [$auth, $mock] = $this->auth();
        $claims = $auth->verifyIdToken($this->f->idToken(), 'n-1');

        $this->assertSame('usr_1', $claims['sub']);
        $this->assertSame('ada@example.ca', $claims['email']);
        $this->assertSame('https://login.example.ca/.well-known/openid-configuration', (string) $mock->request(0)->getUri());
        $this->assertSame('https://login.example.ca/.well-known/jwks.json', (string) $mock->request(1)->getUri());
    }

    public function testAValidAccessTokenPasses(): void
    {
        [$auth] = $this->auth();
        $claims = $auth->verifyAccessToken($this->f->accessToken());
        $this->assertSame('openid email', $claims['scope']);
        // Both typ spellings RFC 9068 allows.
        $this->assertSame('usr_1', $auth->verifyAccessToken($this->f->accessToken([], ['typ' => 'application/at+jwt']))['sub']);
    }

    public function testKeysAreFetchedOnceForManyTokens(): void
    {
        [$auth, $mock] = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $auth->verifyAccessToken($this->f->accessToken());
        }
        $this->assertSame(2, $mock->count());
    }

    public function testAnIdTokenIsNotAnAccessTokenAndViceVersa(): void
    {
        [$auth] = $this->auth();
        $this->assertRefused('wrong_type', fn () => $auth->verifyAccessToken($this->f->idToken()));
        $this->assertRefused('wrong_type', fn () => $auth->verifyIdToken($this->f->accessToken()));
        $this->assertRefused('wrong_type', fn () => $auth->verifyIdToken($this->f->idToken([], ['typ' => 'logout+jwt'])));
        // typ "JWT" is an ID token.
        $this->assertSame('usr_1', $auth->verifyIdToken($this->f->idToken([], ['typ' => 'JWT']))['sub']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badClaims(): array
    {
        return [
            'another issuer' => [['iss' => 'https://login.other.ca'], 'wrong_issuer'],
            'issuer with trailing slash' => [['iss' => 'https://login.example.ca/'], 'wrong_issuer'],
            'another app' => [['aud' => 'hka_client_2'], 'wrong_audience'],
            'several audiences, no azp' => [['aud' => ['hka_client_1', 'other']], 'wrong_audience'],
            'expired' => [['exp' => 1_800_000_000 - 61], 'expired'],
            'issued in the future' => [['iat' => 1_800_000_000 + 120], 'not_yet_valid'],
            'not before' => [['nbf' => 1_800_000_000 + 120], 'not_yet_valid'],
            'no subject' => [['sub' => ''], 'missing_subject'],
            'no expiry' => [['exp' => null], 'malformed'],
            'wrong nonce' => [['nonce' => 'n-2'], 'nonce_mismatch'],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('badClaims')]
    public function testBadClaimsAreRefused(array $override, string $reason): void
    {
        [$auth] = $this->auth();
        $this->assertRefused($reason, fn () => $auth->verifyIdToken($this->f->idToken($override), 'n-1'));
    }

    public function testSeveralAudiencesPassWithAzp(): void
    {
        [$auth] = $this->auth();
        $claims = $auth->verifyIdToken($this->f->idToken(['aud' => ['hka_client_1', 'other'], 'azp' => 'hka_client_1']));
        $this->assertSame('usr_1', $claims['sub']);
    }

    public function testAnAccessTokenForAnotherClientIsRefused(): void
    {
        [$auth] = $this->auth();
        $this->assertRefused('wrong_audience', fn () => $auth->verifyAccessToken($this->f->accessToken(['client_id' => 'hka_client_2'])));
    }

    public function testLeewayCoversSmallClockSkew(): void
    {
        [$auth] = $this->auth();
        $this->assertSame('usr_1', $auth->verifyIdToken($this->f->idToken(['exp' => $this->f->now - 30]))['sub']);
    }

    public function testAMissingNonceIsRefusedWhenOneIsExpected(): void
    {
        [$auth] = $this->auth();
        $this->assertRefused('nonce_mismatch', fn () => $auth->verifyIdToken($this->f->idToken(['nonce' => null]), 'n-1'));
    }

    public function testOnlyEs256IsAccepted(): void
    {
        [$auth, $mock] = $this->auth();
        $this->assertRefused('unsupported_algorithm', fn () => $auth->verifyIdToken($this->f->idToken([], ['alg' => 'none'])));
        $this->assertRefused('unsupported_algorithm', fn () => $auth->verifyIdToken($this->f->idToken([], ['alg' => 'HS256'])));
        $this->assertRefused('unsupported_algorithm', fn () => $auth->verifyIdToken($this->f->idToken([], ['alg' => 'RS256'])));
        // Refused before any key is fetched.
        $this->assertSame(0, $mock->count());
    }

    public function testAnUnsignedTokenIsRefused(): void
    {
        [$auth] = $this->auth();
        [$h, $p] = explode('.', $this->f->idToken());
        $this->assertRefused('malformed', fn () => $auth->verifyIdToken("$h.$p."));
        $this->assertRefused('malformed', fn () => $auth->verifyIdToken("$h.$p"));
    }

    public function testATamperedPayloadFailsTheSignature(): void
    {
        [$auth] = $this->auth();
        [$h, , $s] = explode('.', $this->f->idToken());
        $forged = Jwt::b64urlEncode((string) json_encode([
            'iss' => AuthFixture::ISSUER, 'sub' => 'usr_admin', 'aud' => AuthFixture::CLIENT_ID,
            'iat' => $this->f->now, 'exp' => $this->f->now + 600,
        ]));
        $this->assertRefused('bad_signature', fn () => $auth->verifyIdToken("$h.$forged.$s"));
    }

    public function testATokenSignedByAnotherKeyWithTheSameKidFails(): void
    {
        [$auth] = $this->auth();
        $other = new AuthFixture();
        $this->assertRefused('bad_signature', fn () => $auth->verifyIdToken($other->idToken()));
    }

    public function testCritHeadersAreRefused(): void
    {
        [$auth] = $this->auth();
        $this->assertRefused('malformed', fn () => $auth->verifyIdToken($this->f->idToken([], ['crit' => ['exp']])));
    }

    public function testAHugeTokenIsRefusedUnread(): void
    {
        [$auth, $mock] = $this->auth();
        $this->assertRefused('too_large', fn () => $auth->verifyIdToken(str_repeat('a', Jwt::MAX_LENGTH + 1)));
        $this->assertSame(0, $mock->count());
    }

    public function testNonSigningAndNonP256KeysAreIgnored(): void
    {
        $jwk = $this->f->jwk();
        $mock = new MockHttp([
            $this->f->discoveryResponse(),
            $this->f->jwksResponse([['use' => 'enc'] + $jwk, ['alg' => 'ES384'] + $jwk, ['crv' => 'P-384'] + $jwk]),
        ]);
        $auth = $this->f->auth($mock);
        $this->assertRefused('unknown_key', fn () => $auth->verifyIdToken($this->f->idToken()));
    }

    public function testTheRawSignatureConvertsToDer(): void
    {
        $raw = str_repeat("\x00", 31) . "\x01" . "\x80" . str_repeat("\x00", 31);
        // R = 1 (leading zeros dropped); S has its high bit set (a zero byte keeps it positive).
        $this->assertSame("\x30\x26\x02\x01\x01\x02\x21\x00\x80" . str_repeat("\x00", 31), Jwt::rawSignatureToDer($raw));
        $this->assertNull(Jwt::rawSignatureToDer('short'));
    }

    /** @return array<string, array{string}> */
    public static function badIssuers(): array
    {
        return [
            'http' => ['http://login.example.ca'],
            'path' => ['https://login.example.ca/auth'],
            'query' => ['https://login.example.ca?x=1'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badIssuers')]
    public function testTheIssuerMustBeAnHttpsHostname(string $issuer): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HonkioAuth(['issuer' => $issuer, 'client_id' => 'c']);
    }

    public function testATrailingSlashOnTheIssuerSettingIsDropped(): void
    {
        $auth = new HonkioAuth(['issuer' => 'https://login.example.ca/', 'client_id' => 'c']);
        $this->assertSame('https://login.example.ca', $auth->issuer());
    }

    public function testAClientIdIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HonkioAuth(['issuer' => 'https://login.example.ca', 'client_id' => '']);
    }

    public function testExceptionMessagesNeverCarryTheToken(): void
    {
        [$auth] = $this->auth();
        $token = $this->f->idToken(['aud' => 'nope']);
        try {
            $auth->verifyIdToken($token);
            $this->fail('expected a refusal');
        } catch (TokenVerificationException $e) {
            $this->assertStringNotContainsString(explode('.', $token)[1], $e->getMessage());
        }
    }

    public function testLogoutUrl(): void
    {
        [$auth] = $this->auth();
        $url = $auth->logoutUrl('id.tok.en', 'https://app.example.ca/bye', 's1');
        $this->assertSame(
            'https://login.example.ca/logout?client_id=hka_client_1&id_token_hint=id.tok.en&post_logout_redirect_uri=https%3A%2F%2Fapp.example.ca%2Fbye&state=s1',
            $url,
        );
    }

    public function testTokenSetFromACodeExchange(): void
    {
        [$auth] = $this->auth();
        $set = $auth->tokenSetFromResponse([
            'access_token' => $this->f->accessToken(),
            'id_token' => $this->f->idToken(),
            'refresh_token' => 'hka_rt_1',
            'expires_in' => 600,
            'scope' => 'openid email',
            'token_type' => 'Bearer',
        ], 'n-1');

        $this->assertSame('usr_1', $set->subject);
        $this->assertSame($this->f->now + 600, $set->expiresAt);
        $this->assertSame('hka_rt_1', $set->refreshToken);
        $this->assertStringNotContainsString('hka_rt_1', print_r($set, true));
    }

    public function testTokenSetFromACodeExchangeChecksTheNonce(): void
    {
        [$auth] = $this->auth();
        $this->assertRefused('nonce_mismatch', fn () => $auth->tokenSetFromResponse([
            'access_token' => $this->f->accessToken(),
            'id_token' => $this->f->idToken(),
        ], 'another-nonce'));
    }
}
