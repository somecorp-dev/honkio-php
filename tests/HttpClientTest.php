<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Honkio\Exception\ConnectionException;
use Honkio\Exception\HonkioException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\NotFoundException;
use Honkio\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class HttpClientTest extends TestCase
{
    private function client(MockHttp $mock): HttpClient
    {
        return new HttpClient('k', 'https://api.honkio.ca', $mock->client);
    }

    public function testSendsTheStandardHeaders(): void
    {
        $mock = new MockHttp([new Response(200, [], '{}')]);
        $this->client($mock)->call('GET', '/v1/messages');

        $request = $mock->request();
        $this->assertSame('Bearer k', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('honkio-php/0.1.0', $request->getHeaderLine('User-Agent'));
        $this->assertFalse($request->hasHeader('Content-Type'));
        $this->assertFalse($request->hasHeader('Idempotency-Key'));
        $this->assertSame('https://api.honkio.ca/v1/messages', (string) $request->getUri());
    }

    public function testBodyAddsContentTypeAndIdempotencyKey(): void
    {
        $mock = new MockHttp([new Response(200, [], '{}')]);
        $this->client($mock)->call('POST', '/v1/messages', [], [], ['to' => '+1'], 'order-1');

        $request = $mock->request();
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('order-1', $request->getHeaderLine('Idempotency-Key'));
        $this->assertSame('POST', $request->getMethod());
    }

    public function testNullsAreDroppedFromBodies(): void
    {
        $mock = new MockHttp([new Response(200, [], '{}')]);
        $this->client($mock)->call('POST', '/v1/messages', [], [], [
            'from' => '+1',
            'media_url' => null,
            'nested' => ['a' => 1, 'b' => null],
            'variables' => ['keep' => null],
        ]);

        $this->assertSame(
            '{"from":"+1","nested":{"a":1},"variables":{"keep":null}}',
            (string) $mock->request()->getBody(),
        );
    }

    public function testListBodiesStayLists(): void
    {
        $mock = new MockHttp([new Response(200, [], '[]')]);
        $this->client($mock)->call('POST', '/v1/emails/batch', [], [], [['to' => 'a@b.ca', 'x' => null], ['to' => 'c@d.ca']]);

        $this->assertSame('[{"to":"a@b.ca"},{"to":"c@d.ca"}]', (string) $mock->request()->getBody());
    }

    public function testDatetimesAreIso8601InBodiesAndQueries(): void
    {
        $mock = new MockHttp([new Response(200, [], '{}'), new Response(200, [], '{}')]);
        $client = $this->client($mock);
        $when = new \DateTimeImmutable('2026-10-14T12:30:00-04:00');
        $client->call('POST', '/v1/emails', [], [], ['scheduled_at' => $when]);
        $client->call('GET', '/v1/emails', [], ['created_after' => $when]);

        $this->assertSame(['scheduled_at' => '2026-10-14T16:30:00.000Z'], $mock->jsonBody(0));
        $this->assertSame('created_after=2026-10-14T16%3A30%3A00.000Z', $mock->request(1)->getUri()->getQuery());
    }

    public function testQueryListsRepeatTheKeyAndBoolsAndNullsAreHandled(): void
    {
        $mock = new MockHttp([new Response(200, [], '{}')]);
        $this->client($mock)->call('GET', '/v1/emails', [], [
            'a' => [1, 2],
            'flag' => true,
            'off' => false,
            'skip' => null,
            'limit' => 10,
            'q' => 'a b+c',
        ]);

        $this->assertSame('a=1&a=2&flag=true&off=false&limit=10&q=a%20b%2Bc', $mock->request()->getUri()->getQuery());
    }

    public function testPathIdentifiersAreEncoded(): void
    {
        $mock = new MockHttp([new Response(204)]);
        $this->client($mock)->call('DELETE', '/v1/compliance/consents/{phone}', ['phone' => '+14165550100']);

        $this->assertSame('https://api.honkio.ca/v1/compliance/consents/%2B14165550100', (string) $mock->request()->getUri());
    }

    public function testRefusedIdentifiersSendNothing(): void
    {
        foreach (['', '.', '..'] as $bad) {
            $mock = new MockHttp([new Response(200, [], '{}')]);
            try {
                $this->client($mock)->call('GET', '/v1/email-domains/{id}', ['id' => $bad]);
                $this->fail('expected an exception');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, $mock->count());
            }
        }
    }

    public function test204ReturnsNull(): void
    {
        $mock = new MockHttp([new Response(204)]);
        $this->assertNull($this->client($mock)->call('DELETE', '/v1/email-domains/{id}', ['id' => 'd1']));
    }

    public function testErrorStatusesReachTheParser(): void
    {
        $mock = new MockHttp([MockHttp::json(404, ['code' => 'NOT_FOUND', 'message' => 'no'])]);
        $this->expectException(NotFoundException::class);
        $this->client($mock)->call('GET', '/v1/messages/{id}', ['id' => 'x']);
    }

    public function testConnectFailuresBecomeConnectionExceptions(): void
    {
        $mock = new MockHttp([new ConnectException('boom', new Request('GET', 'https://api.honkio.ca/v1/messages'))]);
        try {
            $this->client($mock)->call('GET', '/v1/messages');
            $this->fail('expected an exception');
        } catch (ConnectionException $e) {
            $this->assertInstanceOf(HonkioException::class, $e);
            $this->assertStringContainsString('boom', $e->getMessage());
        }
    }

    public function testRawBodiesReturnTheStringAndSendNoJsonHeaders(): void
    {
        $mock = new MockHttp([new Response(200, [], "From: a\r\n\r\nbody"), MockHttp::json(404, ['code' => 'NOT_FOUND', 'message' => 'no'])]);
        $client = $this->client($mock);
        $this->assertSame("From: a\r\n\r\nbody", $client->callRaw('GET', '/v1/emails/received/{id}/raw', ['id' => 'r1']));
        $this->assertFalse($mock->request()->hasHeader('Content-Type'));

        $this->expectException(NotFoundException::class);
        $client->callRaw('GET', '/v1/emails/received/{id}/raw', ['id' => 'r2']);
    }

    public function testTimeoutOptionIsPassed(): void
    {
        $mock = new MockHttp([new Response(200, [], '{}')]);
        (new HttpClient('k', 'https://api.honkio.ca', $mock->client, 12.5))->call('GET', '/v1/messages');

        $this->assertSame(12.5, $mock->history[0]['options']['timeout']);
    }
}
