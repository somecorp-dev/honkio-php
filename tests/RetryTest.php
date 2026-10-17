<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Honkio\Exception\ApiException;
use Honkio\Exception\ConnectionException;
use Honkio\Exception\InternalServerException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\RateLimitException;
use Honkio\Honkio;
use Honkio\Http\HttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryTest extends TestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var list<float> */
    private array $waits = [];

    private function client(MockHttp $mock, int $maxRetries = 2, float $maxRetryDelay = 60.0): HttpClient
    {
        return new HttpClient('k', 'https://api.honkio.ca', $mock->client, 30.0, $maxRetries, $maxRetryDelay, function (float $seconds): void {
            $this->waits[] = $seconds;
        });
    }

    /** @param array<string, mixed>|null $details */
    private static function error(int $status, string $code, ?array $details = null, ?string $retryAfter = null): Response
    {
        $body = ['code' => $code, 'message' => $code, 'statusCode' => $status];
        if ($details !== null) {
            $body['details'] = $details;
        }
        $headers = ['Content-Type' => 'application/json'];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = $retryAfter;
        }

        return new Response($status, $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function sent(): Response
    {
        return MockHttp::json(201, ['id' => 'msg_1']);
    }

    /** @return array<mixed> */
    private function send(HttpClient $client, ?string $idempotencyKey = null): array
    {
        return $client->callArray('POST', '/v1/messages', [], [], ['from' => '+14165550100', 'to' => '+16135550123', 'body' => 'Hi'], $idempotencyKey);
    }

    private static function connectError(): ConnectException
    {
        return new ConnectException('Connection reset by peer', new Request('GET', 'https://api.honkio.ca/'));
    }

    public function testNumberRateLimitedWaitsOutRetryAfter(): void
    {
        $mock = new MockHttp([self::error(429, 'NUMBER_RATE_LIMITED', ['retry_after_seconds' => 7], '7'), self::sent()]);

        $this->assertSame(['id' => 'msg_1'], $this->send($this->client($mock)));
        $this->assertSame(2, $mock->count());
        $this->assertSame([7.0], $this->waits);
    }

    public function testCarrierThrottledIsRetried(): void
    {
        $mock = new MockHttp([self::error(503, 'CARRIER_THROTTLED', ['retry_after_seconds' => 2], '2'), self::sent()]);

        $this->assertSame(['id' => 'msg_1'], $this->send($this->client($mock)));
        $this->assertSame(2, $mock->count());
        $this->assertSame([2.0], $this->waits);
    }

    public function testDetailsRetryAfterSecondsWhenTheHeaderIsMissing(): void
    {
        $mock = new MockHttp([self::error(503, 'CARRIER_THROTTLED', ['retry_after_seconds' => 3]), self::sent()]);
        $this->send($this->client($mock));

        $this->assertSame([3.0], $this->waits);
    }

    public function testCarrierUnavailableBacksOffWithJitter(): void
    {
        $mock = new MockHttp([self::error(503, 'CARRIER_UNAVAILABLE'), self::error(503, 'CARRIER_UNAVAILABLE'), self::sent()]);

        $this->assertSame(['id' => 'msg_1'], $this->send($this->client($mock)));
        $this->assertCount(2, $this->waits);
        $this->assertGreaterThanOrEqual(0.25, $this->waits[0]);
        $this->assertLessThanOrEqual(0.5, $this->waits[0]);
        $this->assertGreaterThanOrEqual(0.5, $this->waits[1]);
        $this->assertLessThanOrEqual(1.0, $this->waits[1]);
    }

    public function testGivesUpAfterMaxRetriesWithTheUsualException(): void
    {
        $throttled = static fn () => self::error(503, 'CARRIER_THROTTLED', ['retry_after_seconds' => 1], '1');
        $mock = new MockHttp([$throttled(), $throttled(), $throttled(), self::sent()]);
        try {
            $this->send($this->client($mock));
            $this->fail('expected an exception');
        } catch (InternalServerException $e) {
            $this->assertSame('CARRIER_THROTTLED', $e->errorCode);
            $this->assertSame(503, $e->statusCode);
            $this->assertSame(['retry_after_seconds' => 1], $e->details);
        }
        $this->assertSame(3, $mock->count());
    }

    /** @return array<string, array{int, string}> */
    public static function neverRetried(): array
    {
        return [
            'CARRIER_TIMEOUT' => [503, 'CARRIER_TIMEOUT'],
            'DAILY_LIMIT_REACHED' => [429, 'DAILY_LIMIT_REACHED'],
            'RECIPIENT_RATE_LIMITED' => [429, 'RECIPIENT_RATE_LIMITED'],
            'NUMBER_QUEUE_FULL' => [429, 'NUMBER_QUEUE_FULL'],
            'SENDING_PAUSED' => [403, 'SENDING_PAUSED'],
        ];
    }

    #[DataProvider('neverRetried')]
    public function testNeverRetried(int $status, string $code): void
    {
        $mock = new MockHttp([self::error($status, $code, null, '1'), self::sent()]);
        try {
            $this->send($this->client($mock));
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
        $this->assertSame(1, $mock->count());
        $this->assertSame([], $this->waits);
    }

    public function testAWaitAboveTheMaximumIsNotWaitedOut(): void
    {
        $mock = new MockHttp([self::error(429, 'NUMBER_RATE_LIMITED', ['retry_after_seconds' => 61], '61'), self::sent()]);
        try {
            $this->send($this->client($mock));
            $this->fail('expected an exception');
        } catch (RateLimitException $e) {
            $this->assertSame(['retry_after_seconds' => 61], $e->details);
        }
        $this->assertSame(1, $mock->count());
        $this->assertSame([], $this->waits);
    }

    public function testTheMaximumDelayIsConfigurable(): void
    {
        $mock = new MockHttp([self::error(429, 'NUMBER_RATE_LIMITED', null, '6'), self::sent()]);
        $this->expectException(RateLimitException::class);
        $this->send($this->client($mock, maxRetryDelay: 5.0));
    }

    public function testZeroRetriesTurnsRetryingOff(): void
    {
        $mock = new MockHttp([self::error(429, 'NUMBER_RATE_LIMITED', null, '1'), self::sent()]);
        try {
            $this->send($this->client($mock, maxRetries: 0));
            $this->fail('expected an exception');
        } catch (RateLimitException) {
        }
        $this->assertSame(1, $mock->count());
        $this->assertSame([], $this->waits);
    }

    public function testRetryOptionsReachTheClient(): void
    {
        // max_retries 0 through the Honkio options: one request, the error thrown.
        $mock = new MockHttp([self::error(503, 'CARRIER_UNAVAILABLE'), self::sent()]);
        $honkio = new Honkio('k', ['http_client' => $mock->client, 'max_retries' => 0]);
        try {
            $honkio->messages->send(['from' => '+14165550100', 'to' => '+16135550123', 'body' => 'Hi']);
            $this->fail('expected an exception');
        } catch (InternalServerException) {
        }
        $this->assertSame(1, $mock->count());
    }

    public function testNegativeRetryOptionsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Honkio('k', ['max_retries' => -1]);
    }

    public function testRateLimitedIsRetriedOnlyWhenRepeatingIsHarmless(): void
    {
        $limited = static fn () => self::error(429, 'RATE_LIMITED', null, '1');
        $mock = new MockHttp([
            $limited(), self::sent(),                        // a keyed send
            $limited(), MockHttp::json(200, ['data' => []]), // a GET
            $limited(), self::sent(),                        // verify start: a repeat would send a second code
        ]);
        $client = $this->client($mock);

        $this->assertSame(['id' => 'msg_1'], $this->send($client));
        $this->assertSame(['data' => []], $client->callArray('GET', '/v1/messages'));
        try {
            $client->callArray('POST', '/v1/verify', [], [], ['to' => '+16135550123']);
            $this->fail('expected an exception');
        } catch (RateLimitException $e) {
            $this->assertSame('RATE_LIMITED', $e->errorCode);
        }
        $this->assertSame(5, $mock->count());
    }

    public function testRetryDelayPrefersTheHeaderThenDetailsThenBackoff(): void
    {
        $this->assertSame(4.0, HttpClient::retryDelay('4', ['retry_after_seconds' => 9], 0));
        $this->assertSame(9.0, HttpClient::retryDelay(null, ['retry_after_seconds' => 9], 0));
        $this->assertSame(2.0, HttpClient::retryDelay('soon', null, 2, static fn (): float => 1.0));
        $this->assertSame(1.0, HttpClient::retryDelay(null, null, 2, static fn (): float => 0.0));
    }

    // Idempotency keys

    public function testAGeneratedKeyIsSentAndKeptAcrossRetries(): void
    {
        $mock = new MockHttp([self::error(503, 'CARRIER_THROTTLED', null, '1'), self::error(503, 'CARRIER_UNAVAILABLE'), self::sent()]);
        $this->send($this->client($mock));

        $keys = array_map(fn (int $i): string => $mock->request($i)->getHeaderLine('Idempotency-Key'), [0, 1, 2]);
        $this->assertMatchesRegularExpression(self::UUID, $keys[0]);
        $this->assertCount(1, array_unique($keys));
    }

    public function testEachCallGetsItsOwnKey(): void
    {
        $mock = new MockHttp([self::sent(), self::sent()]);
        $client = $this->client($mock);
        $this->send($client);
        $this->send($client);

        $this->assertNotSame($mock->request(0)->getHeaderLine('Idempotency-Key'), $mock->request(1)->getHeaderLine('Idempotency-Key'));
    }

    public function testTheCallersKeyWinsOnEveryRetry(): void
    {
        $mock = new MockHttp([self::error(429, 'NUMBER_RATE_LIMITED', null, '1'), self::sent()]);
        $this->send($this->client($mock), 'order-42');

        $this->assertSame('order-42', $mock->request(0)->getHeaderLine('Idempotency-Key'));
        $this->assertSame('order-42', $mock->request(1)->getHeaderLine('Idempotency-Key'));
    }

    public function testKeyedRoutes(): void
    {
        [$honkio, $mock] = MockHttp::honkio([self::sent(), self::sent(), MockHttp::json(200, ['data' => []]), self::sent()]);
        $honkio->messages->send(['from' => '+14165550100', 'to' => '+16135550123', 'body' => 'Hi']);
        $honkio->emails->send(['from' => 'a@mail.acme.ca', 'to' => 'b@example.com', 'subject' => 'Hi', 'text' => 'Hi']);
        $honkio->batch->send([['from' => 'a@mail.acme.ca', 'to' => 'b@example.com', 'subject' => 'Hi', 'text' => 'Hi']]);
        $honkio->verify->start(['from' => '+14165550100', 'to' => '+16135550123']);

        foreach ([0, 1, 2] as $i) {
            $this->assertMatchesRegularExpression(self::UUID, $mock->request($i)->getHeaderLine('Idempotency-Key'));
        }
        $this->assertFalse($mock->request(3)->hasHeader('Idempotency-Key'));
    }

    // Connection errors

    public function testAGetIsRetriedAfterAConnectionReset(): void
    {
        $mock = new MockHttp([self::connectError(), MockHttp::json(200, ['id' => 'msg_1'])]);

        $this->assertSame(['id' => 'msg_1'], $this->client($mock)->callArray('GET', '/v1/messages/{id}', ['id' => 'msg_1']));
        $this->assertSame(2, $mock->count());
        $this->assertCount(1, $this->waits);
    }

    public function testAKeyedPostIsRetriedAfterAConnectionError(): void
    {
        $mock = new MockHttp([self::connectError(), self::sent()]);

        $this->assertSame(['id' => 'msg_1'], $this->send($this->client($mock)));
        $this->assertSame($mock->request(0)->getHeaderLine('Idempotency-Key'), $mock->request(1)->getHeaderLine('Idempotency-Key'));
    }

    public function testAPostWithoutAKeyIsNotRetriedAfterAConnectionError(): void
    {
        $mock = new MockHttp([self::connectError(), self::sent()]);
        try {
            $this->client($mock)->callArray('POST', '/v1/verify', [], [], ['to' => '+16135550123']);
            $this->fail('expected an exception');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('Connection reset', $e->getMessage());
        }
        $this->assertSame(1, $mock->count());
        $this->assertSame([], $this->waits);
    }

    public function testARawGetIsRetried(): void
    {
        $mock = new MockHttp([self::connectError(), new Response(200, [], 'raw')]);

        $this->assertSame('raw', $this->client($mock)->callRaw('GET', '/v1/emails/received/{id}/raw', ['id' => 'in_1']));
    }
}
