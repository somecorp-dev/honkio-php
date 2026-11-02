<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Honkio;
use Honkio\Http\HttpClient;
use PHPUnit\Framework\TestCase;

/** A client dumped, printed or encoded never shows the API key or the client secret. */
final class RedactionTest extends TestCase
{
    private const KEY = 'mk_live_supersecretkey0123456789';
    private const SECRET = 'hka_cs_supersecretclientsecret0123';

    /** @return list<string> */
    private static function shownAs(object $value): array
    {
        ob_start();
        var_dump($value);
        $dumped = (string) ob_get_clean();

        return [$dumped, print_r($value, true), (string) json_encode($value), print_r(['nested' => $value], true)];
    }

    public function testHonkioNeverShowsTheKey(): void
    {
        $honkio = new Honkio(self::KEY, ['http_client' => (new MockHttp())->client, 'base_url' => 'https://api.example.test']);
        foreach (self::shownAs($honkio) as $shown) {
            $this->assertStringNotContainsString(self::KEY, $shown);
        }
        foreach (self::shownAs($honkio->emails) as $shown) {
            $this->assertStringNotContainsString(self::KEY, $shown);
        }
        $this->assertStringContainsString('[redacted]', print_r($honkio, true));
        $this->assertStringContainsString('https://api.example.test', print_r($honkio, true));
    }

    public function testHttpClientNeverShowsTheKeyButStillSendsIt(): void
    {
        $mock = new MockHttp([new Response(200, [], '{}')]);
        $http = new HttpClient(self::KEY, 'https://api.example.test', $mock->client, maxRetries: 0);
        foreach (self::shownAs($http) as $shown) {
            $this->assertStringNotContainsString(self::KEY, $shown);
        }
        $http->call('GET', '/v1/messages');
        $this->assertSame('Bearer ' . self::KEY, $mock->request()->getHeaderLine('Authorization'));
    }

    public function testTheKeyStaysOutOfAStackTrace(): void
    {
        try {
            new HttpClient(self::KEY, 'https://api.example.test', (new MockHttp())->client, maxRetries: -1);
            $this->fail('expected an exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getTraceAsString());
            $this->assertStringNotContainsString(self::KEY, print_r($e->getTrace(), true));
        }
    }

    public function testHonkioAuthAndItsRefresherNeverShowTheClientSecret(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('needs ext-openssl');
        }
        $auth = (new AuthFixture())->auth(new MockHttp(), null, self::SECRET);
        foreach (self::shownAs($auth) as $shown) {
            $this->assertStringNotContainsString(self::SECRET, $shown);
        }
        $this->assertStringContainsString(AuthFixture::CLIENT_ID, print_r($auth, true));
        $this->assertStringContainsString('[redacted]', print_r($auth, true));
    }
}
