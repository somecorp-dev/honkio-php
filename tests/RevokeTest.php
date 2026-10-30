<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Honkio\Auth\Cache;
use Honkio\Auth\InMemoryCache;
use Honkio\Auth\RefreshResult;
use Honkio\Auth\TokenSet;
use Honkio\Exception\AuthenticationException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\IssuerUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HonkioAuth::revoke(): sign-out revokes the refresh token at Honkio (RFC
 * 7009) and first deletes the refresh memo for it, so neither the revoked
 * token nor the token it replaced is handed the session's tokens afterwards.
 */
final class RevokeTest extends TestCase
{
    private AuthFixture $f;

    protected function setUp(): void
    {
        $this->f = new AuthFixture();
    }

    private function expiredSet(): TokenSet
    {
        return new TokenSet('old.access.token', 'hka_rt_old', 'old.id.token', $this->f->now - 5, 'usr_1', 'openid email');
    }

    private function tokenResponse(): Response
    {
        return MockHttp::json(200, [
            'access_token' => $this->f->accessToken(),
            'id_token' => $this->f->idToken(['nonce' => null]),
            'refresh_token' => 'hka_rt_new',
            'token_type' => 'Bearer',
            'expires_in' => 600,
        ]);
    }

    public function testItPostsTheRefreshTokenToTheRevocationEndpointWithClientSecretBasic(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), new Response(200)]);
        $this->f->auth($mock)->revoke('hka_rt_1');

        $post = $mock->request(1);
        $this->assertSame('POST', $post->getMethod());
        $this->assertSame(AuthFixture::ISSUER . '/revoke', (string) $post->getUri());
        $this->assertSame('Basic ' . base64_encode('hka_client_1:shh'), $post->getHeaderLine('Authorization'));
        parse_str((string) $post->getBody(), $form);
        $this->assertSame(['token' => 'hka_rt_1', 'token_type_hint' => 'refresh_token'], $form);
    }

    public function testAPublicClientSendsItsClientIdAndAMissingEndpointFallsBackToSlashRevoke(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(['revocation_endpoint' => null]), new Response(200)]);
        $this->f->auth($mock, null, null)->revoke('hka_rt_1');

        $post = $mock->request(1);
        $this->assertSame(AuthFixture::ISSUER . '/revoke', (string) $post->getUri());
        $this->assertFalse($post->hasHeader('Authorization'));
        parse_str((string) $post->getBody(), $form);
        $this->assertSame('hka_client_1', $form['client_id']);
    }

    /** @return array<string, array{string}> */
    public static function revokedTokens(): array
    {
        return [
            'the old refresh token' => ['hka_rt_old'],
            'the new refresh token it was rotated to' => ['hka_rt_new'],
        ];
    }

    /** @return array<string, array{\Closure(): Cache}> */
    public static function caches(): array
    {
        return [
            'a cache with delete()' => [fn (): Cache => new InMemoryCache()],
            'a cache without delete()' => [fn (): Cache => new class () implements Cache {
                private InMemoryCache $inner;

                public function __construct()
                {
                    $this->inner = new InMemoryCache();
                }

                public function get(string $key): ?array
                {
                    return $this->inner->get($key);
                }

                public function set(string $key, array $value, int $ttlSeconds): void
                {
                    $this->inner->set($key, $value, $ttlSeconds);
                }
            }],
        ];
    }

    /** @return iterable<string, array{string, \Closure(): Cache}> */
    public static function revokedTokensAndCaches(): iterable
    {
        foreach (self::revokedTokens() as $t => [$token]) {
            foreach (self::caches() as $c => [$cache]) {
                yield "$t, $c" => [$token, $cache];
            }
        }
    }

    /**
     * Without the purge, a request still carrying the old refresh token within
     * the memo window would get the new tokens from the cache after sign-out.
     *
     * @param \Closure(): Cache $cache
     */
    #[DataProvider('revokedTokensAndCaches')]
    public function testRevokingForgetsTheRefreshMemo(string $revoked, \Closure $cache): void
    {
        $mock = new MockHttp([
            $this->f->discoveryResponse(),
            $this->tokenResponse(),
            $this->f->jwksResponse(),
            new Response(200),
            MockHttp::json(400, ['error' => 'invalid_grant']),
        ]);
        $auth = $this->f->auth($mock, $cache());

        $this->assertSame(RefreshResult::REFRESHED, $auth->refresh($this->expiredSet())->status);
        $auth->revoke($revoked);
        $replayed = $auth->refresh($this->expiredSet());

        $this->assertSame(RefreshResult::ENDED, $replayed->status, 'the memo did not hand out the revoked session');
        $this->assertSame('rejected', $replayed->reason);
        $this->assertSame(5, $mock->count());
    }

    public function testTheMemoIsForgottenEvenWhenHonkioCannotBeReached(): void
    {
        $mock = new MockHttp([
            $this->f->discoveryResponse(),
            $this->tokenResponse(),
            $this->f->jwksResponse(),
            new ConnectException('Connection refused', new Request('POST', AuthFixture::ISSUER . '/revoke')),
            MockHttp::json(400, ['error' => 'invalid_grant']),
        ]);
        $auth = $this->f->auth($mock);
        $auth->refresh($this->expiredSet());

        try {
            $auth->revoke('hka_rt_old');
            $this->fail('revoke() should have thrown');
        } catch (IssuerUnavailableException) {
        }
        $this->assertSame(RefreshResult::ENDED, $auth->refresh($this->expiredSet())->status);
    }

    /** @return array<string, array{Response}> */
    public static function unavailable(): array
    {
        return [
            '503' => [new Response(503)],
            '429' => [MockHttp::json(429, ['error' => 'slow_down'])],
            '408' => [new Response(408)],
        ];
    }

    #[DataProvider('unavailable')]
    public function testHonkioNotHandlingItIsIssuerUnavailable(Response $answer): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $answer]);
        $this->expectException(IssuerUnavailableException::class);
        $this->f->auth($mock)->revoke('hka_rt_1');
    }

    public function testAClientHonkioRefusesIsAnAuthenticationException(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), MockHttp::json(401, ['error' => 'invalid_client', 'error_description' => 'Client authentication failed'])]);
        try {
            $this->f->auth($mock)->revoke('hka_rt_1');
            $this->fail('revoke() should have thrown');
        } catch (AuthenticationException $e) {
            $this->assertSame('invalid_client', $e->errorCode);
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('Client authentication failed', $e->getMessage());
        }
    }

    public function testAnEmptyTokenIsRefusedWithoutARequest(): void
    {
        $mock = new MockHttp([]);
        try {
            $this->f->auth($mock)->revoke('');
            $this->fail('revoke() should have thrown');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(0, $mock->count());
    }
}
