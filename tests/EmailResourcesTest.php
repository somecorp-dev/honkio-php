<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;

final class EmailResourcesTest extends TestCase
{
    private const BASE = 'https://api.honkio.ca';

    private function assertRequest(MockHttp $mock, string $method, string $uri, int $index = 0): void
    {
        $this->assertSame($method, $mock->request($index)->getMethod());
        $this->assertSame(self::BASE . $uri, (string) $mock->request($index)->getUri());
    }

    public function testEmailsSendWithIdempotencyKey(): void
    {
        [$honkio, $mock] = MockHttp::honkio([MockHttp::json(200, ['id' => 'e1'])]);
        $result = $honkio->emails->send(
            ['from' => 'a@b.ca', 'to' => ['c@d.ca'], 'subject' => 'Hi', 'text' => 'Hello', 'headers' => ['X-Order' => '1'], 'scheduled_at' => null],
            'mail-1',
        );

        $this->assertSame(['id' => 'e1'], $result);
        $this->assertRequest($mock, 'POST', '/v1/emails');
        $this->assertSame(
            ['from' => 'a@b.ca', 'to' => ['c@d.ca'], 'subject' => 'Hi', 'text' => 'Hello', 'headers' => ['X-Order' => '1'], 'scheduled_at' => null],
            $mock->jsonBody(),
        );
        $this->assertSame('mail-1', $mock->request()->getHeaderLine('Idempotency-Key'));
    }

    public function testEmailsGetListUpdateCancel(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 'e1']),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['id' => 'e1']),
            new Response(204),
        ]);
        $honkio->emails->get('e1');
        $honkio->emails->list(['limit' => 5]);
        $honkio->emails->update('e1', ['scheduled_at' => new \DateTimeImmutable('2026-10-15T09:00:00Z')]);
        $this->assertNull($honkio->emails->cancel('e1'));

        $this->assertRequest($mock, 'GET', '/v1/emails/e1');
        $this->assertRequest($mock, 'GET', '/v1/emails?limit=5', 1);
        $this->assertRequest($mock, 'PATCH', '/v1/emails/e1', 2);
        $this->assertSame(['scheduled_at' => '2026-10-15T09:00:00.000Z'], $mock->jsonBody(2));
        $this->assertRequest($mock, 'DELETE', '/v1/emails/e1', 3);
    }

    public function testReceivedEmails(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['address' => 'x@in.honkio.ca']),
            MockHttp::json(200, ['enabled' => true]),
            MockHttp::json(200, ['id' => 'r1']),
            new Response(200, [], "From: a@b.ca\r\n\r\nbody"),
            new Response(200, [], "\x00\x01binary"),
            MockHttp::json(200, ['id' => 'r2']),
        ]);
        $honkio->emails->received->list(['limit' => 1]);
        $honkio->emails->received->address();
        $honkio->emails->received->setAddressEnabled(true);
        $honkio->emails->received->get('r1', ['include' => 'body']);
        $this->assertSame("From: a@b.ca\r\n\r\nbody", $honkio->emails->received->raw('r1'));
        $this->assertSame("\x00\x01binary", $honkio->emails->received->attachment('r1', 'a/1'));
        $honkio->emails->received->simulate(['from' => 'x@y.ca']);

        $expected = [
            ['GET', '/v1/emails/received?limit=1'], ['GET', '/v1/emails/received/address'], ['PATCH', '/v1/emails/received/address'],
            ['GET', '/v1/emails/received/r1?include=body'], ['GET', '/v1/emails/received/r1/raw'],
            ['GET', '/v1/emails/received/r1/attachments/a%2F1'], ['POST', '/v1/emails/received/simulate'],
        ];
        foreach ($expected as $i => [$method, $uri]) {
            $this->assertRequest($mock, $method, $uri, $i);
        }
        $this->assertSame(['enabled' => true], $mock->jsonBody(2));
    }

    public function testReceivedRawErrorsAreTheJsonEnvelope(): void
    {
        [$honkio] = MockHttp::honkio([MockHttp::json(404, ['code' => 'NOT_FOUND', 'message' => 'No such email.'])]);
        $this->expectException(NotFoundException::class);
        $honkio->emails->received->raw('missing');
    }

    public function testBatchSendsAListWithAnIdempotencyKey(): void
    {
        [$honkio, $mock] = MockHttp::honkio([MockHttp::json(200, ['data' => []]), MockHttp::json(200, ['batch_id' => 'b'])]);
        $honkio->batch->send([['from' => 'a@b.ca', 'to' => 'c@d.ca', 'subject' => 's', 'text' => 't']], 'batch-1');
        $honkio->batch->send(['from' => 'a@b.ca', 'template' => 'welcome', 'recipients' => [['to' => 'c@d.ca']]]);

        $this->assertRequest($mock, 'POST', '/v1/emails/batch');
        $this->assertSame('batch-1', $mock->request()->getHeaderLine('Idempotency-Key'));
        $this->assertSame([['from' => 'a@b.ca', 'to' => 'c@d.ca', 'subject' => 's', 'text' => 't']], $mock->jsonBody());
        $this->assertFalse($mock->request(1)->hasHeader('Idempotency-Key'));
    }

    public function testDomains(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 'd1']),
            MockHttp::json(200, ['id' => 'd1']),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['id' => 'd1']),
            MockHttp::json(200, ['status' => 'pending']),
            new Response(204),
        ]);
        $honkio->domains->create(['domain' => 'example.com']);
        $honkio->domains->get('d1');
        $honkio->domains->list();
        $honkio->domains->update('d1', ['open_tracking' => true]);
        $honkio->domains->verify('d1');
        $this->assertNull($honkio->domains->remove('d1'));

        $expected = [
            ['POST', '/v1/email-domains'], ['GET', '/v1/email-domains/d1'], ['GET', '/v1/email-domains'],
            ['PATCH', '/v1/email-domains/d1'], ['POST', '/v1/email-domains/d1/verify'], ['DELETE', '/v1/email-domains/d1'],
        ];
        foreach ($expected as $i => [$method, $uri]) {
            $this->assertRequest($mock, $method, $uri, $i);
        }
        $this->assertSame(['domain' => 'example.com'], $mock->jsonBody());
    }

    public function testSuppressions(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['email_address' => 'a@b.ca', 'reason' => 'manual']),
            MockHttp::json(200, ['data' => []]),
            new Response(204),
        ]);
        $honkio->suppressions->create(['email_address' => 'a@b.ca']);
        $honkio->suppressions->list(['limit' => 3]);
        $this->assertNull($honkio->suppressions->remove('a@b.ca'));

        $this->assertRequest($mock, 'POST', '/v1/email-suppressions');
        $this->assertSame(['email_address' => 'a@b.ca', 'reason' => 'manual'], $mock->jsonBody());
        $this->assertRequest($mock, 'GET', '/v1/email-suppressions?limit=3', 1);
        $this->assertRequest($mock, 'DELETE', '/v1/email-suppressions/a%40b.ca', 2);
    }

    public function testTemplatesUpdateCanClearAFieldWithNull(): void
    {
        [$honkio, $mock] = MockHttp::honkio([MockHttp::json(200, ['id' => 't1'])]);
        $honkio->templates->update('tpl', ['html' => null]);

        $this->assertSame('{"html":null}', (string) $mock->request()->getBody());
    }

    public function testTemplatesKeepVariablesKeysAsGiven(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 't1']),
            MockHttp::json(200, ['id' => 't1']),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['id' => 't1']),
            MockHttp::json(200, ['id' => 't1']),
            MockHttp::json(200, ['id' => 't1']),
            MockHttp::json(200, ['data' => []]),
            new Response(204),
        ]);
        $honkio->templates->create(['name' => 'Welcome', 'variables' => [['key' => 'firstName']]]);
        $honkio->templates->get('welcome');
        $honkio->templates->list(['limit' => 2]);
        $honkio->templates->update('welcome', ['subject' => 'Hi {{{firstName}}}']);
        $honkio->templates->publish('welcome');
        $honkio->templates->rollback('welcome', ['version' => 2]);
        $honkio->templates->versions('welcome');
        $this->assertNull($honkio->templates->remove('welcome'));

        $this->assertSame(['name' => 'Welcome', 'variables' => [['key' => 'firstName']]], $mock->jsonBody());
        $expected = [
            ['POST', '/v1/email-templates'], ['GET', '/v1/email-templates/welcome'], ['GET', '/v1/email-templates?limit=2'],
            ['PATCH', '/v1/email-templates/welcome'], ['POST', '/v1/email-templates/welcome/publish'],
            ['POST', '/v1/email-templates/welcome/rollback'], ['GET', '/v1/email-templates/welcome/versions'],
            ['DELETE', '/v1/email-templates/welcome'],
        ];
        foreach ($expected as $i => [$method, $uri]) {
            $this->assertRequest($mock, $method, $uri, $i);
        }
        $this->assertSame(['version' => 2], $mock->jsonBody(5));
    }
}
