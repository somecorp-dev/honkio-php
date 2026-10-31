<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Honkio\Auth\Cache;
use Honkio\Auth\HonkioAuth;
use Honkio\Auth\InMemoryCache;
use Honkio\Auth\OutageBreaker;
use Honkio\Auth\RefreshResult;
use Honkio\Auth\TokenSet;
use PHPUnit\Framework\TestCase;

/**
 * The outage pause: once several sessions in a row can't reach an app's
 * issuer, that app's refreshes keep their sessions without calling Honkio
 * for a few seconds. The pause belongs to one app (issuer and client id),
 * lives in the shared cache rather than the PHP process, and is never started
 * by one session alone or by a Honkio that answers (408 and 429 included).
 */
final class OutageBreakerTest extends TestCase
{
    private const OTHER_ISSUER = 'https://login.other.ca';

    private AuthFixture $f;
    private InMemoryCache $cache;

    protected function setUp(): void
    {
        $this->f = new AuthFixture();
        $this->cache = new InMemoryCache(fn (): float => (float) $this->f->now);
    }

    private function session(string $refreshToken): TokenSet
    {
        return new TokenSet('old.access.token', $refreshToken, 'old.id.token', $this->f->now - 5, 'usr_1', 'openid');
    }

    private function down(): ConnectException
    {
        return new ConnectException('Connection refused', new Request('POST', AuthFixture::ISSUER . '/token'));
    }

    private function auth(MockHttp $mock, string $issuer = AuthFixture::ISSUER, string $clientId = AuthFixture::CLIENT_ID, ?Cache $cache = null): HonkioAuth
    {
        return new HonkioAuth([
            'issuer' => $issuer,
            'client_id' => $clientId,
            'client_secret' => 'shh',
            'http_client' => $mock->client,
            'cache' => $cache ?? $this->cache,
            'clock' => fn (): int => $this->f->now,
        ]);
    }

    private function discoveryFor(string $issuer): Response
    {
        return MockHttp::json(200, [
            'issuer' => $issuer,
            'authorization_endpoint' => "$issuer/authorize",
            'token_endpoint' => "$issuer/token",
            'jwks_uri' => "$issuer/.well-known/jwks.json",
        ]);
    }

    /** Three different sessions fail to reach Honkio: the app's issuer now looks down. */
    private function trip(HonkioAuth $auth): void
    {
        foreach (['hka_rt_a', 'hka_rt_b', 'hka_rt_c'] as $token) {
            $this->assertSame('unreachable', $auth->refresh($this->session($token))->reason);
        }
    }

    public function testThreeSessionsInARowThatCannotReachHonkioPauseTheAppsRefreshes(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->down(), $this->down(), $this->down(), $this->down()]);
        $auth = $this->auth($mock);
        $this->trip($auth);
        $this->assertSame(4, $mock->count());

        $paused = $auth->refresh($this->session('hka_rt_d'));
        $this->assertSame(RefreshResult::KEPT, $paused->status);
        $this->assertTrue($paused->isOffline());
        $this->assertSame(4, $mock->count(), 'no request while the pause lasts');

        $this->f->now += OutageBreaker::PAUSE_SECONDS;
        $auth->refresh($this->session('hka_rt_d'));
        $this->assertSame(5, $mock->count(), 'the pause is over: Honkio is asked again');
    }

    public function testThePauseNeverKeepsASessionPastTheTwentyFourHourLimit(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->down(), $this->down(), $this->down()]);
        $auth = $this->auth($mock);
        $this->trip($auth);

        $old = new TokenSet('a', 'hka_rt_d', 'i', $this->f->now - 86401, 'usr_1');
        $result = $auth->refresh($old);
        $this->assertSame(RefreshResult::ENDED, $result->status);
        $this->assertSame('outage_limit', $result->reason);
    }

    public function testThePauseIsPerAppNotPerProcess(): void
    {
        $down = new MockHttp([$this->f->discoveryResponse(), $this->down(), $this->down(), $this->down()]);
        $this->trip($this->auth($down));

        // Another app (another issuer) on the same cache, in the same process: not paused.
        $other = new MockHttp([$this->discoveryFor(self::OTHER_ISSUER), $this->down()]);
        $this->assertSame(RefreshResult::KEPT, $this->auth($other, self::OTHER_ISSUER)->refresh($this->session('hka_rt_x'))->status);
        $this->assertSame(2, $other->count(), 'another app still asks its own issuer');

        // Another client on the same issuer: not paused either.
        $client = new MockHttp([$this->down()]);
        $this->auth($client, AuthFixture::ISSUER, 'hka_client_2')->refresh($this->session('hka_rt_y'));
        $this->assertSame(1, $client->count());

        // The same app in another PHP process sharing the cache: paused.
        $sameApp = new MockHttp([]);
        $this->assertTrue($this->auth($sameApp)->refresh($this->session('hka_rt_z'))->isOffline());
        $this->assertSame(0, $sameApp->count());
    }

    public function testOneSessionFailingAgainAndAgainDoesNotPauseTheOthers(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->down(), $this->down(), $this->down(), $this->down()]);
        $auth = $this->auth($mock);
        for ($i = 0; $i < 3; $i++) {
            $auth->refresh($this->session('hka_rt_same'));
        }
        $auth->refresh($this->session('hka_rt_other'));
        $this->assertSame(5, $mock->count());
    }

    public function testFailuresOutsideTheWindowDoNotAddUp(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->down(), $this->down(), $this->down(), $this->down()]);
        $auth = $this->auth($mock);
        $auth->refresh($this->session('hka_rt_a'));
        $this->f->now += OutageBreaker::TRIP_WINDOW_SECONDS + 1;
        $auth->refresh($this->session('hka_rt_b'));
        $auth->refresh($this->session('hka_rt_c'));
        $auth->refresh($this->session('hka_rt_d'));
        $this->assertSame(5, $mock->count(), 'only two recent failures when the third arrived');
    }

    /** A 429 or 408 is Honkio answering: it never starts the pause, and it ends a run of failures. */
    public function testBusyAnswersNeverPauseAndResetTheCount(): void
    {
        $busy = fn (int $status): Response => MockHttp::json($status, ['error' => 'temporarily_unavailable']);
        $mock = new MockHttp([
            $this->f->discoveryResponse(),
            $busy(429), $busy(408), $busy(429),
            $this->down(), $this->down(), $busy(429), $this->down(),
            $this->down(),
        ]);
        $auth = $this->auth($mock);
        foreach (['a', 'b', 'c'] as $s) {
            $this->assertSame('busy', $auth->refresh($this->session("hka_rt_$s"))->reason);
        }
        foreach (['d', 'e', 'f', 'g'] as $s) {
            $auth->refresh($this->session("hka_rt_$s"));
        }
        $auth->refresh($this->session('hka_rt_h'));
        $this->assertSame(9, $mock->count(), 'every refresh asked Honkio');
    }

    public function testWhilePausedCachedKeysAreUsedWithoutWaitingOnTheIssuer(): void
    {
        $mock = new MockHttp([
            $this->f->discoveryResponse(), $this->f->jwksResponse(),
            // An hour later both are stale: each refresh tries discovery, then the token endpoint.
            $this->down(), $this->down(), $this->down(), $this->down(), $this->down(), $this->down(),
        ]);
        $auth = $this->auth($mock);
        $auth->verifyAccessToken($this->f->accessToken());
        $this->f->now += 3601;
        $this->trip($auth);
        $this->assertSame(8, $mock->count());

        $claims = $auth->verifyAccessToken($this->f->accessToken());
        $this->assertSame('usr_1', $claims['sub']);
        $this->assertSame(8, $mock->count(), 'the stale keys and discovery were used without a fetch');
    }
}
