<?php

declare(strict_types=1);

namespace Honkio\Tests;

use Honkio\Exception\HonkioException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\SignatureVerificationException;
use Honkio\Webhook;
use Honkio\WebhookEvents;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const VECTOR_FILE = __DIR__ . '/../../../web/lib/webhook-signing-vector.ts';
    private const API_EVENTS_FILE = __DIR__ . '/../../../api/src/services/webhookDelivery.ts';
    private const FOREVER = PHP_INT_MAX;

    /** @return array{secret: string, timestamp: string, body: string, signature: string} */
    private static function vector(): array
    {
        if (!Monorepo::present()) {
            self::markTestSkipped(Monorepo::skipReason());
        }
        $source = (string) file_get_contents(self::VECTOR_FILE);
        self::assertSame(1, preg_match('/const WEBHOOK_TEST_VECTOR = `([\s\S]*?)`/', $source, $block), 'vector not found');
        $field = static function (string $name) use ($block): string {
            preg_match('/^' . $name . '\s+(.+)$/m', $block[1], $m);

            return trim($m[1] ?? '');
        };
        $vector = [
            'secret' => $field('secret'),
            'timestamp' => $field('timestamp'),
            'body' => $field('body'),
            'signature' => $field('signature'),
        ];
        self::assertNotContains('', $vector, 'the vector is missing a field');

        return $vector;
    }

    /** @return array{string, array<string, string>} */
    private static function signNow(string $body, string $secret = 'whsec_test_secret'): array
    {
        $timestamp = (string) time();

        return [$body, [
            'X-HonkIO-Timestamp' => $timestamp,
            'X-HonkIO-Signature' => hash_hmac('sha256', "$timestamp.$body", $secret),
        ]];
    }

    private function assertRefused(callable $fn): SignatureVerificationException
    {
        try {
            $fn();
        } catch (SignatureVerificationException $e) {
            $this->addToAssertionCount(1);

            return $e;
        }
        $this->fail('expected a SignatureVerificationException');
    }

    public function testThePublishedVectorVerifies(): void
    {
        $v = self::vector();
        $event = Webhook::verify(
            $v['body'],
            ['X-HonkIO-Timestamp' => $v['timestamp'], 'X-HonkIO-Signature' => $v['signature']],
            $v['secret'],
            self::FOREVER,
        );

        $this->assertSame('message.delivered', $event['type']);
        $this->assertSame('acc_test', $event['account_id']);
    }

    public function testTheVectorIsStaleAtTheDefaultTolerance(): void
    {
        $v = self::vector();
        $this->assertRefused(fn () => Webhook::verify($v['body'], ['x-honkio-timestamp' => $v['timestamp'], 'x-honkio-signature' => $v['signature']], $v['secret']));
    }

    public function testAHexDecodedSecretDoesNotVerify(): void
    {
        $v = self::vector();
        $decoded = (string) hex2bin($v['secret']);
        $this->assertRefused(fn () => Webhook::verify($v['body'], ['x-honkio-timestamp' => $v['timestamp'], 'x-honkio-signature' => $v['signature']], $decoded, self::FOREVER));
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        [$body, $headers] = self::signNow('{"type":"message.sent"}');
        foreach ([$headers, array_change_key_case($headers, CASE_LOWER), array_change_key_case($headers, CASE_UPPER)] as $h) {
            $this->assertSame(['type' => 'message.sent'], Webhook::verify($body, $h, 'whsec_test_secret'));
        }
    }

    public function testHeaderValuesMayBeLists(): void
    {
        [$body, $headers] = self::signNow('{"type":"message.sent"}');
        $psr = [
            'x-honkio-timestamp' => [$headers['X-HonkIO-Timestamp']],
            'x-honkio-signature' => [$headers['X-HonkIO-Signature']],
        ];
        $this->assertSame(['type' => 'message.sent'], Webhook::verify($body, $psr, 'whsec_test_secret'));
    }

    public function testAnEmojiBodyVerifiesAsTheOriginalBytes(): void
    {
        $body = json_encode(['type' => 'message.received', 'data' => ['body' => "Merci \u{1F44D} caf\u{E9}"]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        [, $headers] = self::signNow($body);
        $event = Webhook::verify($body, $headers, 'whsec_test_secret');

        $this->assertSame("Merci \u{1F44D} caf\u{E9}", $event['data']['body']);
    }

    public function testAnUppercaseHexSignatureIsAccepted(): void
    {
        [$body, $headers] = self::signNow('{"a":1}');
        $headers['X-HonkIO-Signature'] = strtoupper($headers['X-HonkIO-Signature']);
        $this->assertSame(['a' => 1], Webhook::verify($body, $headers, 'whsec_test_secret'));
    }

    public function testAWrongSecretIsRefused(): void
    {
        [$body, $headers] = self::signNow('{"a":1}');
        $e = $this->assertRefused(fn () => Webhook::verify($body, $headers, 'other'));
        $this->assertStringContainsString('does not match', $e->getMessage());
    }

    public function testATamperedBodyIsRefused(): void
    {
        [, $headers] = self::signNow('{"a":1}');
        $this->assertRefused(fn () => Webhook::verify('{"a":2}', $headers, 'whsec_test_secret'));
    }

    public function testMissingOrMalformedHeadersAreRefused(): void
    {
        [$body, $headers] = self::signNow('{"a":1}');
        $this->assertRefused(fn () => Webhook::verify($body, [], 'whsec_test_secret'));
        $this->assertRefused(fn () => Webhook::verify($body, ['X-HonkIO-Timestamp' => $headers['X-HonkIO-Timestamp']], 'whsec_test_secret'));
        $this->assertRefused(fn () => Webhook::verify($body, [...$headers, 'X-HonkIO-Timestamp' => '12ab'], 'whsec_test_secret'));
        $this->assertRefused(fn () => Webhook::verify($body, [...$headers, 'X-HonkIO-Signature' => 'abc123'], 'whsec_test_secret'));
        $this->assertRefused(fn () => Webhook::verify($body, [...$headers, 'X-HonkIO-Timestamp' => "123\n"], 'whsec_test_secret'));
    }

    public function testToleranceIsHonouredInBothDirections(): void
    {
        $body = '{"a":1}';
        foreach ([time() - 301, time() + 301] as $ts) {
            $headers = ['X-HonkIO-Timestamp' => (string) $ts, 'X-HonkIO-Signature' => hash_hmac('sha256', "$ts.$body", 's')];
            $this->assertRefused(fn () => Webhook::verify($body, $headers, 's'));
            $this->assertSame(['a' => 1], Webhook::verify($body, $headers, 's', 400));
        }
    }

    public function testASignedBodyThatIsNotAJsonObjectIsRefused(): void
    {
        foreach (['not json', '[1,2]', '"x"'] as $body) {
            [, $headers] = self::signNow($body);
            $this->assertRefused(fn () => Webhook::verify($body, $headers, 'whsec_test_secret'));
        }
    }

    public function testAMissingSecretIsAnInvalidArgument(): void
    {
        [$body, $headers] = self::signNow('{"a":1}');
        $this->expectException(InvalidArgumentException::class);
        Webhook::verify($body, $headers, '');
    }

    public function testBothExceptionsAreHonkioExceptions(): void
    {
        $this->assertInstanceOf(HonkioException::class, new SignatureVerificationException());
        $this->assertInstanceOf(HonkioException::class, new InvalidArgumentException());
    }

    public function testTheWebhooksResourceDelegates(): void
    {
        [$honkio] = MockHttp::honkio([]);
        [$body, $headers] = self::signNow('{"type":"x"}');
        $this->assertSame(['type' => 'x'], $honkio->webhooks->verify($body, $headers, 'whsec_test_secret'));
    }

    public function testWebhookEventsMatchTheApiUnion(): void
    {
        if (!Monorepo::present()) {
            $this->markTestSkipped(Monorepo::skipReason());
        }
        $this->assertFileExists(self::API_EVENTS_FILE, 'api/src/services/webhookDelivery.ts is missing; was it renamed?');
        $source = (string) file_get_contents(self::API_EVENTS_FILE);
        $this->assertSame(1, preg_match('/export type WebhookEventType =([\s\S]*?);/', $source, $block), 'WebhookEventType not found');
        preg_match_all("/'([a-z_.]+)'/", $block[1], $matches);
        $apiEvents = $matches[1];
        $this->assertGreaterThan(20, count($apiEvents));

        $sdk = WebhookEvents::ALL;
        sort($sdk);
        sort($apiEvents);
        $this->assertSame($apiEvents, $sdk);
    }
}
