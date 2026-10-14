<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Route-aware request shaping: opaque email maps, date-only fields, datetimes. */
final class RequestShapeTest extends TestCase
{
    private function ok(int $count = 1): array
    {
        return array_fill(0, $count, MockHttp::json(200, ['id' => 'x']));
    }

    public function testTemplateVariablesStayAnArrayEvenWhenEmpty(): void
    {
        [$honkio, $mock] = MockHttp::honkio($this->ok(2));
        $honkio->templates->update('welcome', ['variables' => []]);
        $honkio->templates->create(['name' => 'W', 'variables' => [['key' => 'firstName', 'fallback' => 'friend']]]);

        $this->assertSame('{"variables":[]}', (string) $mock->request(0)->getBody());
        $this->assertSame(
            '{"name":"W","variables":[{"key":"firstName","fallback":"friend"}]}',
            (string) $mock->request(1)->getBody(),
        );
    }

    public function testSimulateHeadersStayAnArrayEvenWhenEmpty(): void
    {
        [$honkio, $mock] = MockHttp::honkio($this->ok());
        $honkio->emails->received->simulate(['from' => 'a@b.ca', 'headers' => []]);

        $this->assertSame('{"from":"a@b.ca","headers":[]}', (string) $mock->request()->getBody());
    }

    public function testEmailsSendForcesEmptyVariablesAndHeadersToObjects(): void
    {
        [$honkio, $mock] = MockHttp::honkio($this->ok());
        $honkio->emails->send(['from' => 'a@b.ca', 'to' => ['c@d.ca'], 'variables' => [], 'headers' => []]);

        $this->assertSame('{"from":"a@b.ca","to":["c@d.ca"],"variables":{},"headers":{}}', (string) $mock->request()->getBody());
    }

    public function testBatchSendForcesEmptyVariablesToObjects(): void
    {
        [$honkio, $mock] = MockHttp::honkio($this->ok());
        $honkio->batch->send([['to' => 'a@b.ca', 'headers' => []], ['to' => 'c@d.ca', 'variables' => []]]);

        $this->assertSame('[{"to":"a@b.ca","headers":{}},{"to":"c@d.ca","variables":{}}]', (string) $mock->request()->getBody());
    }

    public function testMessagesListDatesAreDateOnly(): void
    {
        [$honkio, $mock] = MockHttp::honkio($this->ok(2));
        $honkio->messages->list([
            'date_from' => new \DateTimeImmutable('2026-10-01T22:00:00-04:00'),
            'date_to' => '2026-10-31',
        ]);
        $honkio->messages->list(['date_from' => new \DateTimeImmutable('2026-10-01T00:00:00Z')]);

        // 22:00 at -04:00 is already the 2nd in UTC.
        $this->assertSame('date_from=2026-10-02&date_to=2026-10-31', $mock->request(0)->getUri()->getQuery());
        $this->assertSame('date_from=2026-10-01', $mock->request(1)->getUri()->getQuery());
    }

    public function testConsentLastTransactionDateIsDateOnly(): void
    {
        [$honkio, $mock] = MockHttp::honkio($this->ok());
        $honkio->consents->create([
            'phone_number' => '+14165550123',
            'last_transaction_date' => new \DateTimeImmutable('2026-09-30T12:00:00Z'),
        ]);

        $this->assertSame('2026-09-30', $mock->jsonBody()['last_transaction_date']);
    }

    public function testDatetimeFieldsAreUtcWithZ(): void
    {
        [$honkio, $mock] = MockHttp::honkio($this->ok());
        $honkio->emails->send([
            'from' => 'a@b.ca',
            'scheduled_at' => new \DateTimeImmutable('2026-10-14T12:30:00-04:00'),
        ]);

        $this->assertSame('2026-10-14T16:30:00.000Z', $mock->jsonBody()['scheduled_at']);
    }

    public function testSuppressionsCreateNeedsAnEmailAddress(): void
    {
        [$honkio, $mock] = MockHttp::honkio([new Response(200, [], '{}')]);
        try {
            $honkio->suppressions->create([]);
            $this->fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('email_address', $e->getMessage());
        }
        $this->assertSame(0, $mock->count());
    }
}
