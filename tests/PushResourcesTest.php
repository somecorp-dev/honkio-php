<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Endpoints;
use PHPUnit\Framework\TestCase;

final class PushResourcesTest extends TestCase
{
    private const BASE = 'https://api.honkio.ca';

    private function assertRequest(MockHttp $mock, string $method, string $uri, int $index = 0): void
    {
        $this->assertSame($method, $mock->request($index)->getMethod());
        $this->assertSame(self::BASE . $uri, (string) $mock->request($index)->getUri());
    }

    public function testAppsCrud(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(201, ['id' => 'pa_1']),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['id' => 'pa_1']),
            MockHttp::json(200, ['id' => 'pa_1']),
            new Response(204),
        ]);
        $params = [
            'name' => 'Acme',
            'apns' => ['key_p8' => 'P8', 'key_id' => 'K1', 'team_id' => 'T1', 'bundle_id' => 'ca.acme.app'],
            'fcm' => ['service_account_json' => '{}'],
        ];
        $this->assertSame(['id' => 'pa_1'], $honkio->push->apps->create($params));
        $this->assertRequest($mock, 'POST', '/v1/push/apps', 0);
        $this->assertSame($params, $mock->jsonBody(0));
        $honkio->push->apps->list(['limit' => 5]);
        $this->assertRequest($mock, 'GET', '/v1/push/apps?limit=5', 1);
        $honkio->push->apps->get('pa_1');
        $this->assertRequest($mock, 'GET', '/v1/push/apps/pa_1', 2);
        $honkio->push->apps->update('pa_1', ['name' => 'Renamed', 'placeholder_body' => null]);
        $this->assertRequest($mock, 'PATCH', '/v1/push/apps/pa_1', 3);
        $this->assertSame(['name' => 'Renamed', 'placeholder_body' => null], $mock->jsonBody(3));
        $this->assertNull($honkio->push->apps->delete('pa_1'));
        $this->assertRequest($mock, 'DELETE', '/v1/push/apps/pa_1', 4);
    }

    public function testEncryption(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 'pa_1']),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(201, ['kid' => 'k_2']),
            MockHttp::json(200, ['kid' => 'k_2']),
        ]);
        $honkio->push->encryption->set('pa_1', true);
        $this->assertRequest($mock, 'POST', '/v1/push/apps/pa_1/encryption', 0);
        $this->assertSame(['enabled' => true], $mock->jsonBody(0));
        $honkio->push->encryption->listKeys('pa_1');
        $this->assertRequest($mock, 'GET', '/v1/push/apps/pa_1/encryption/keys', 1);
        $honkio->push->encryption->createKey('pa_1');
        $this->assertRequest($mock, 'POST', '/v1/push/apps/pa_1/encryption/keys', 2);
        $honkio->push->encryption->activateKey('pa_1', 'k_2');
        $this->assertRequest($mock, 'POST', '/v1/push/apps/pa_1/encryption/keys/k_2/activate', 3);
    }

    public function testDevices(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 'pd_1']),
            MockHttp::json(200, ['data' => []]),
            new Response(204),
        ]);
        $device = ['app_id' => 'pa_1', 'token' => 'tok', 'platform' => 'ios', 'user_id' => 'u_1', 'apns_environment' => 'sandbox'];
        $honkio->push->devices->register($device);
        $this->assertRequest($mock, 'PUT', '/v1/push/devices', 0);
        $this->assertSame($device, $mock->jsonBody(0));
        $honkio->push->devices->list(['app_id' => 'pa_1', 'user_id' => 'u_1', 'active' => true]);
        $this->assertRequest($mock, 'GET', '/v1/push/devices?app_id=pa_1&user_id=u_1&active=true', 1);
        $this->assertNull($honkio->push->devices->delete('pd_1'));
        $this->assertRequest($mock, 'DELETE', '/v1/push/devices/pd_1', 2);
    }

    public function testSendWithAFallback(): void
    {
        $fallback = ['sms' => ['to' => '+14165550100', 'from' => '+14165550199', 'body' => 'Hi']];
        [$honkio, $mock] = MockHttp::honkio([MockHttp::json(202, ['id' => 'ps_1', 'status' => 'queued', 'fallback' => $fallback])]);
        $push = $honkio->push->send([
            'app_id' => 'pa_1',
            'to' => ['user_id' => 'u_1'],
            'title' => 'Hello',
            'data' => ['orderId' => '42'],
            'thread_id' => 't1',
            'collapse_key' => 'order-42',
            'fallback' => $fallback,
        ], 'push-1');

        $this->assertSame($fallback, $push['fallback']);
        $this->assertRequest($mock, 'POST', '/v1/push');
        $this->assertSame('push-1', $mock->request()->getHeaderLine('Idempotency-Key'));
        $this->assertSame([
            'app_id' => 'pa_1',
            'to' => ['user_id' => 'u_1'],
            'title' => 'Hello',
            'data' => ['orderId' => '42'],
            'thread_id' => 't1',
            'collapse_key' => 'order-42',
            'fallback' => $fallback,
        ], $mock->jsonBody());
    }

    public function testSendGeneratesAnIdempotencyKeyAndKeepsAnEmptyDataAsAnObject(): void
    {
        [$honkio, $mock] = MockHttp::honkio([MockHttp::json(202, ['id' => 'ps_1'])]);
        $honkio->push->send(['app_id' => 'pa_1', 'to' => ['device_id' => 'pd_1'], 'body' => 'x', 'data' => []]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $mock->request()->getHeaderLine('Idempotency-Key'));
        $this->assertStringContainsString('"data":{}', (string) $mock->request()->getBody());
    }

    public function testGetListUsage(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 'ps_1']),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['month' => '2026-11']),
        ]);
        $honkio->push->get('ps_1');
        $this->assertRequest($mock, 'GET', '/v1/push/ps_1', 0);
        $honkio->push->list(['app_id' => 'pa_1', 'created_after' => new \DateTimeImmutable('2026-11-01T00:00:00Z')]);
        $this->assertSame('GET', $mock->request(1)->getMethod());
        $this->assertSame('/v1/push', $mock->request(1)->getUri()->getPath());
        $this->assertStringContainsString('created_after=2026-11-01T00%3A00%3A00.000Z', $mock->request(1)->getUri()->getQuery());
        $this->assertSame(['month' => '2026-11'], $honkio->push->usage());
        $this->assertRequest($mock, 'GET', '/v1/push/usage', 2);
    }

    public function testTheRoutesMatchTheNodeSdk(): void
    {
        $file = __DIR__ . '/../../node/src/routes.ts';
        if (!is_file($file)) {
            $this->markTestSkipped('reads the Node SDK, which this checkout does not have');
        }
        preg_match_all("/push\\w*: \\['(GET|POST|PUT|PATCH|DELETE)', '([^']+)'\\]/", (string) file_get_contents($file), $m, PREG_SET_ORDER);
        $node = array_map(static fn (array $r): string => "$r[1] $r[2]", $m);
        $php = array_map(static fn (array $r): string => "$r[2] $r[3]", Endpoints::PUSH);
        sort($node);
        sort($php);
        $this->assertCount(16, $node);
        $this->assertSame($node, $php);
    }
}
