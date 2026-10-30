<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Honkio\Auth\InMemoryCache;
use Honkio\Auth\KeySet;
use Honkio\Exception\IssuerUnavailableException;
use Honkio\Exception\TokenVerificationException;
use PHPUnit\Framework\TestCase;

/** Discovery and JWKS caching: keys keep working through an outage, and rotate. */
final class KeySetTest extends TestCase
{
    private AuthFixture $f;
    private InMemoryCache $cache;

    protected function setUp(): void
    {
        $this->f = new AuthFixture();
        $this->cache = new InMemoryCache(fn (): float => (float) $this->f->now);
    }

    private function down(): ConnectException
    {
        return new ConnectException('Connection refused', new Request('GET', AuthFixture::ISSUER));
    }

    public function testCachedKeysVerifyTokensWhileHonkioIsDown(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->f->jwksResponse(), $this->down(), $this->down()]);
        $auth = $this->f->auth($mock, $this->cache);
        $auth->verifyAccessToken($this->f->accessToken());

        // Hours later, both discovery and keys are stale and Honkio is down.
        $this->f->now += 6 * 3600;
        $claims = $auth->verifyIdToken($this->f->idToken());
        $this->assertSame('usr_1', $claims['sub']);
    }

    public function testTheCacheIsSharedAcrossInstances(): void
    {
        $first = new MockHttp([$this->f->discoveryResponse(), $this->f->jwksResponse()]);
        $this->f->auth($first, $this->cache)->verifyIdToken($this->f->idToken());

        $second = new MockHttp([]);
        $this->f->auth($second, $this->cache)->verifyIdToken($this->f->idToken());
        $this->assertSame(0, $second->count());
    }

    public function testWithNothingCachedAnOutageIsIssuerUnavailable(): void
    {
        $mock = new MockHttp([$this->down()]);
        $this->expectException(IssuerUnavailableException::class);
        $this->f->auth($mock, $this->cache)->verifyIdToken($this->f->idToken());
    }

    public function testARotatedKeyIsFetched(): void
    {
        $newKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($newKey);
        $mock = new MockHttp([
            $this->f->discoveryResponse(),
            $this->f->jwksResponse(),
            $this->f->jwksResponse([$this->f->jwk(), $this->f->jwk($newKey, 'key_2')]),
        ]);
        $auth = $this->f->auth($mock, $this->cache);
        $auth->verifyIdToken($this->f->idToken());

        $this->f->now += KeySet::REFETCH_MIN_SECONDS;
        $rotated = $this->f->sign(['iss' => AuthFixture::ISSUER, 'sub' => 'usr_1', 'aud' => AuthFixture::CLIENT_ID, 'iat' => $this->f->now, 'exp' => $this->f->now + 600], ['kid' => 'key_2'], $newKey);
        $this->assertSame('usr_1', $auth->verifyIdToken($rotated)['sub']);
        $this->assertSame(3, $mock->count());
    }

    public function testUnknownKidsDoNotHammerTheIssuer(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(), $this->f->jwksResponse()]);
        $auth = $this->f->auth($mock, $this->cache);
        $auth->verifyIdToken($this->f->idToken());

        for ($i = 0; $i < 5; $i++) {
            try {
                $auth->verifyIdToken($this->f->idToken([], ['kid' => "junk_$i"]));
                $this->fail('a junk kid passed');
            } catch (TokenVerificationException $e) {
                $this->assertSame('unknown_key', $e->reason);
            }
        }
        $this->assertSame(2, $mock->count());
    }

    public function testADiscoveryDocumentForAnotherIssuerIsRefused(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(['issuer' => 'https://evil.example.com'])]);
        $this->expectException(IssuerUnavailableException::class);
        $this->f->auth($mock, $this->cache)->configuration();
    }

    public function testAPlainHttpEndpointIsRefused(): void
    {
        $mock = new MockHttp([$this->f->discoveryResponse(['jwks_uri' => 'http://login.example.ca/jwks'])]);
        $this->expectException(IssuerUnavailableException::class);
        $this->f->auth($mock, $this->cache)->configuration();
    }

    public function testDiscoveryIsNotRedirected(): void
    {
        $mock = new MockHttp([new Response(302, ['Location' => 'https://evil.example.com/.well-known/openid-configuration'])]);
        $this->expectException(IssuerUnavailableException::class);
        try {
            $this->f->auth($mock, $this->cache)->configuration();
        } finally {
            $this->assertSame(1, $mock->count());
        }
    }

    public function testInMemoryCacheExpires(): void
    {
        $this->cache->set('k', ['v' => 1], 10);
        $this->assertSame(['v' => 1], $this->cache->get('k'));
        $this->f->now += 10;
        $this->assertNull($this->cache->get('k'));
    }
}
