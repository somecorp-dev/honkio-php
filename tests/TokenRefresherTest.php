<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Honkio\Auth\InMemoryCache;
use Honkio\Auth\RefreshResult;
use Honkio\Auth\TokenRefresher;
use Honkio\Auth\TokenSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The outage rule (spec, "An outage must never lock users out"): a session is
 * kept while Honkio can't be reached, for at most 24 hours past the access
 * token's expiry; a refresh Honkio refuses ends it at once.
 */
final class TokenRefresherTest extends TestCase
{
    private AuthFixture $f;

    protected function setUp(): void
    {
        $this->f = new AuthFixture();
    }

    private function expiredSet(int $expiredSecondsAgo = 5): TokenSet
    {
        return new TokenSet('old.access.token', 'hka_rt_old', 'old.id.token', $this->f->now - $expiredSecondsAgo, 'usr_1', 'openid email');
    }

    /** @param array<string, mixed> $override */
    private function tokenResponse(array $override = []): Response
    {
        return MockHttp::json(200, array_merge([
            'access_token' => $this->f->accessToken(['exp' => $this->f->now + 600]),
            'id_token' => $this->f->idToken(['nonce' => null]),
            'refresh_token' => 'hka_rt_new',
            'token_type' => 'Bearer',
            'expires_in' => 600,
            'scope' => 'openid email',
        ], $override));
    }

    private function down(): ConnectException
    {
        return new ConnectException('Connection refused', new Request('POST', AuthFixture::ISSUER . '/token'));
    }

    public function testAnUnexpiredTokenIsNotRefreshed(): void
    {
        $mock = new MockHttp([]);
        $set = new TokenSet('a', 'r', 'i', $this->f->now + 300, 'usr_1');
        $result = $this->f->auth($mock)->refresh($set);

        $this->assertSame(RefreshResult::VALID, $result->status);
        $this->assertSame($set, $result->tokens);
        $this->assertSame(0, $mock->count());
    }

    public function testATokenAboutToExpireIsRefreshed(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->tokenResponse(), $this->f->jwksResponse()]);
        $set = new TokenSet('a', 'hka_rt_old', 'i', $this->f->now + 10, 'usr_1');
        $this->assertSame(RefreshResult::REFRESHED, $this->f->auth($mock)->refresh($set)->status);
    }

    public function testAnExpiredTokenIsRefreshedWithClientSecretBasic(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->tokenResponse(), $this->f->jwksResponse()]);
        $result = $this->f->auth($mock)->refresh($this->expiredSet());

        $this->assertSame(RefreshResult::REFRESHED, $result->status);
        $this->assertTrue($result->changed());
        $this->assertNotNull($result->tokens);
        $this->assertSame('hka_rt_new', $result->tokens->refreshToken);
        $this->assertSame($this->f->now + 600, $result->tokens->expiresAt);
        $this->assertSame('usr_1', $result->tokens->subject);

        $post = $mock->request(1);
        $this->assertSame('POST', $post->getMethod());
        $this->assertSame(AuthFixture::ISSUER . '/token', (string) $post->getUri());
        $this->assertSame('Basic ' . base64_encode('hka_client_1:shh'), $post->getHeaderLine('Authorization'));
        parse_str((string) $post->getBody(), $form);
        $this->assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'hka_rt_old'], $form);
    }

    public function testAPublicClientSendsItsClientIdInTheBody(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->tokenResponse(), $this->f->jwksResponse()]);
        $this->f->auth($mock, null, null)->refresh($this->expiredSet());

        $post = $mock->request(1);
        $this->assertFalse($post->hasHeader('Authorization'));
        parse_str((string) $post->getBody(), $form);
        $this->assertSame('hka_client_1', $form['client_id']);
    }

    public function testTheOldIdTokenIsKeptWhenNoNewOneComes(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->tokenResponse(['id_token' => null]), $this->f->jwksResponse()]);
        $result = $this->f->auth($mock)->refresh($this->expiredSet());
        $this->assertNotNull($result->tokens);
        $this->assertSame('old.id.token', $result->tokens->idToken);
    }

    /** @return array<string, array{int}> */
    public static function refusals(): array
    {
        return ['400 invalid_grant' => [400], '401 invalid_client' => [401], '403 forbidden' => [403], '404 not found' => [404]];
    }

    #[DataProvider('refusals')]
    public function testARefusalEndsTheSessionAtOnce(int $status): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), MockHttp::json($status, ['error' => 'invalid_grant'])]);
        $result = $this->f->auth($mock)->refresh($this->expiredSet(5));

        $this->assertSame(RefreshResult::ENDED, $result->status);
        $this->assertSame('rejected', $result->reason);
        $this->assertSame('invalid_grant', $result->error);
        $this->assertFalse($result->isActive());
    }

    /** @return array<string, array{\Closure(self): mixed}> */
    public static function outages(): array
    {
        return [
            'connection refused' => [fn (self $t) => $t->down()],
            '500 server error' => [fn () => MockHttp::json(500, ['error' => 'server_error'])],
            '502 from a proxy' => [fn () => new Response(502, [], '<html>Bad gateway</html>')],
            '503 unavailable' => [fn () => new Response(503)],
            '408 request timeout' => [fn () => new Response(408)],
            '429 rate limited' => [fn () => MockHttp::json(429, ['error' => 'slow_down'])],
            'unreadable 200' => [fn () => new Response(200, [], '<html>captive portal</html>')],
        ];
    }

    /** @param \Closure(self): mixed $answer */
    #[DataProvider('outages')]
    public function testAnOutageKeepsTheSession(\Closure $answer): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $answer($this)]);
        $set = $this->expiredSet(3600);
        $result = $this->f->auth($mock)->refresh($set);

        $this->assertSame(RefreshResult::KEPT, $result->status);
        $this->assertSame($set, $result->tokens);
        $this->assertTrue($result->isActive());
        $this->assertFalse($result->changed());
    }

    public function testDiscoveryBeingDownIsAnOutageToo(): void
    {
        $mock = new MockHttp([$this->down()]);
        $result = $this->f->auth($mock)->refresh($this->expiredSet());
        $this->assertSame(RefreshResult::KEPT, $result->status);
    }

    public function testTheOutageGraceEndsTwentyFourHoursPastExpiry(): void
    {
        $mock = new MockHttp([$this->down(), $this->down()]);
        $auth = $this->f->auth($mock);

        $this->assertSame(RefreshResult::KEPT, $auth->refresh($this->expiredSet(86400))->status);
        $result = $auth->refresh($this->expiredSet(86401));
        $this->assertSame(RefreshResult::ENDED, $result->status);
        $this->assertSame('outage_limit', $result->reason);
    }

    public function testNoRefreshTokenEndsTheSessionWithoutARequest(): void
    {
        $mock = new MockHttp([]);
        $set = new TokenSet('a', null, 'i', $this->f->now - 1, 'usr_1');
        $result = $this->f->auth($mock)->refresh($set);
        $this->assertSame('no_refresh_token', $result->reason);
        $this->assertSame(0, $mock->count());
    }

    public function testTokensForAnotherUserEndTheSession(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), MockHttp::json(200, [
            'access_token' => $this->f->accessToken(['sub' => 'usr_2']),
            'id_token' => $this->f->idToken(['sub' => 'usr_2', 'nonce' => null]),
            'refresh_token' => 'r',
        ]), $this->f->jwksResponse()]);
        $result = $this->f->auth($mock)->refresh($this->expiredSet());
        $this->assertSame('subject_changed', $result->reason);
    }

    public function testTokensFromAnotherIssuerEndTheSession(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), MockHttp::json(200, [
            'access_token' => $this->f->accessToken(['iss' => 'https://login.other.ca']),
            'refresh_token' => 'r',
        ]), $this->f->jwksResponse()]);
        $result = $this->f->auth($mock)->refresh($this->expiredSet());
        $this->assertSame('invalid_response', $result->reason);
    }

    public function testNewTokensAreAcceptedWhenOnlyTheKeysAreUnreachable(): void
    {
        // The token endpoint answered over TLS; the JWKS fetch failed.
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->tokenResponse(), $this->down()]);
        $result = $this->f->auth($mock)->refresh($this->expiredSet());
        $this->assertSame(RefreshResult::REFRESHED, $result->status);
    }

    public function testASecondRequestWithTheSameOldTokenGetsTheSameNewTokens(): void
    {
        $cache = new InMemoryCache(fn (): float => (float) $this->f->now);
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->tokenResponse(), $this->f->jwksResponse()]);
        $auth = $this->f->auth($mock, $cache);

        $first = $auth->refresh($this->expiredSet());
        $second = $auth->refresh($this->expiredSet());

        $this->assertSame(RefreshResult::REFRESHED, $second->status);
        $this->assertNotNull($first->tokens);
        $this->assertNotNull($second->tokens);
        $this->assertSame($first->tokens->toArray(), $second->tokens->toArray());
        $this->assertSame(3, $mock->count(), 'the old refresh token was sent once');
    }

    public function testTheNewTokensAreNotHandedToTheOldTokenAfterTheDedupeWindow(): void
    {
        $cache = new InMemoryCache(fn (): float => (float) $this->f->now);
        $mock = new MockHttp([
            $this->f->discoveryResponse(),
            $this->tokenResponse(),
            $this->f->jwksResponse(),
            MockHttp::json(400, ['error' => 'invalid_grant']),
        ]);
        $auth = $this->f->auth($mock, $cache);

        $this->assertSame(RefreshResult::REFRESHED, $auth->refresh($this->expiredSet())->status);
        $this->f->now += TokenRefresher::DEDUPE_SECONDS;
        $replayed = $auth->refresh($this->expiredSet());

        $this->assertSame(RefreshResult::ENDED, $replayed->status);
        $this->assertSame('rejected', $replayed->reason);
        $this->assertSame(4, $mock->count(), 'the replayed old refresh token went to Honkio');
    }

    public function testTokenSetRoundTripsThroughAnArray(): void
    {
        $set = new TokenSet('a', 'r', 'i', 123, 'usr_1', 'openid');
        $this->assertEquals($set, TokenSet::fromArray($set->toArray()));
        $this->assertSame('[redacted]', $set->__debugInfo()['accessToken']);
    }
}
