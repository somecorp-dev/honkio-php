<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Endpoints;
use PHPUnit\Framework\TestCase;

final class NotifyResourcesTest extends TestCase
{
    private const BASE = 'https://api.honkio.ca';

    private function assertRequest(MockHttp $mock, string $method, string $uri, int $index = 0): void
    {
        $this->assertSame($method, $mock->request($index)->getMethod());
        $this->assertSame(self::BASE . $uri, (string) $mock->request($index)->getUri());
    }

    public function testSend(): void
    {
        $attempts = [['channel' => 'push', 'outcome' => 'skipped', 'reason' => 'no_active_device']];
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(202, ['id' => 'ntf_1', 'status' => 'pending', 'attempts' => $attempts]),
            MockHttp::json(202, ['id' => 'ntf_2']),
        ]);
        $params = [
            'user_id' => 'user_42',
            'channels' => ['sms', 'email'],
            'template' => 'order-shipped',
            'variables' => ['firstName' => 'Pat'],
            'language' => 'fr',
            'from' => ['sms' => '+14165550199', 'email' => 'hi@example.com'],
            'push_app_id' => 'pa_1',
            'is_commercial' => false,
        ];
        $notification = $honkio->notify->send($params, 'n-1');
        $this->assertSame($attempts, $notification['attempts']);
        $this->assertRequest($mock, 'POST', '/v1/notify', 0);
        $this->assertSame($params, $mock->jsonBody(0));
        $this->assertSame('n-1', $mock->request(0)->getHeaderLine('Idempotency-Key'));

        $honkio->notify->send(['user_id' => 'user_42', 'text' => 'Your code is 123456', 'variables' => []]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $mock->request(1)->getHeaderLine('Idempotency-Key'));
        $this->assertStringContainsString('"variables":{}', (string) $mock->request(1)->getBody());
    }

    public function testNotifications(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 'ntf_1']),
            MockHttp::json(200, ['data' => []]),
        ]);
        $honkio->notifications->get('ntf_1');
        $this->assertRequest($mock, 'GET', '/v1/notifications/ntf_1', 0);
        $honkio->notifications->list(['user_id' => 'user_42', 'status' => 'failed', 'limit' => 5]);
        $this->assertSame('/v1/notifications', $mock->request(1)->getUri()->getPath());
        parse_str($mock->request(1)->getUri()->getQuery(), $query);
        $this->assertSame(['user_id' => 'user_42', 'status' => 'failed', 'limit' => '5'], $query);
    }

    public function testRecipients(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['user_id' => 'user_42']),
            MockHttp::json(200, ['user_id' => 'user_42']),
            MockHttp::json(200, ['user_id' => 'user 42/x']),
            MockHttp::json(200, ['data' => []]),
            new Response(204),
            MockHttp::json(200, ['user_id' => 'user_42']),
        ]);
        $honkio->recipients->upsert('user_42', ['phone_number' => '+14165550123', 'email' => null, 'language' => 'fr']);
        $this->assertRequest($mock, 'PUT', '/v1/recipients/user_42', 0);
        $this->assertSame(['phone_number' => '+14165550123', 'email' => null, 'language' => 'fr'], $mock->jsonBody(0));
        $honkio->recipients->update('user_42', ['email' => 'pat@example.com']);
        $this->assertRequest($mock, 'PATCH', '/v1/recipients/user_42', 1);
        $this->assertSame(['email' => 'pat@example.com'], $mock->jsonBody(1));
        $honkio->recipients->get('user 42/x');
        $this->assertRequest($mock, 'GET', '/v1/recipients/user%2042%2Fx', 2);
        $honkio->recipients->list(['limit' => 10]);
        $this->assertRequest($mock, 'GET', '/v1/recipients?limit=10', 3);
        $this->assertNull($honkio->recipients->delete('user_42'));
        $this->assertRequest($mock, 'DELETE', '/v1/recipients/user_42', 4);
        $honkio->recipients->upsert('user_42');
        $this->assertSame('{}', (string) $mock->request(5)->getBody());
    }

    public function testTheRoutesMatchTheNodeSdk(): void
    {
        $file = __DIR__ . '/../../node/src/routes.ts';
        if (!is_file($file)) {
            $this->markTestSkipped('reads the Node SDK, which this checkout does not have');
        }
        preg_match_all("/(?:notify|notifications|recipients)\\w*: \\['(GET|POST|PUT|PATCH|DELETE)', '([^']+)'\\]/", (string) file_get_contents($file), $m, PREG_SET_ORDER);
        $node = array_map(static fn (array $r): string => "$r[1] $r[2]", $m);
        $php = array_map(static fn (array $r): string => "$r[2] $r[3]", Endpoints::NOTIFY);
        sort($node);
        sort($php);
        $this->assertCount(8, $node);
        $this->assertSame($node, $php);
    }
}
