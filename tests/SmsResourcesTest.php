<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Exception\HonkioException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\UnprocessableEntityException;
use Honkio\Honkio;
use PHPUnit\Framework\TestCase;

final class SmsResourcesTest extends TestCase
{
    private const BASE = 'https://api.honkio.ca';

    /**
     * @param array<string, mixed> $response
     * @return array{Honkio, MockHttp}
     */
    private function with(array $response = ['ok' => true], int $status = 200): array
    {
        return MockHttp::honkio([MockHttp::json($status, $response)]);
    }

    private function assertRequest(MockHttp $mock, string $method, string $uri, int $index = 0): void
    {
        $this->assertSame($method, $mock->request($index)->getMethod());
        $this->assertSame(self::BASE . $uri, (string) $mock->request($index)->getUri());
    }

    public function testConstructionRequiresAKey(): void
    {
        $previous = getenv('HONKIO_API_KEY');
        putenv('HONKIO_API_KEY');
        try {
            $this->expectException(InvalidArgumentException::class);
            new Honkio();
        } finally {
            if ($previous !== false) {
                putenv('HONKIO_API_KEY=' . $previous);
            }
        }
    }

    public function testKeyComesFromTheEnvironmentAndBaseUrlIsOverridable(): void
    {
        $previous = getenv('HONKIO_API_KEY');
        putenv('HONKIO_API_KEY=env-key');
        try {
            $mock = new MockHttp([MockHttp::json(200, [])]);
            $honkio = new Honkio(null, ['http_client' => $mock->client, 'base_url' => 'https://example.test/']);
            $honkio->messages->list();
        } finally {
            putenv($previous === false ? 'HONKIO_API_KEY' : 'HONKIO_API_KEY=' . $previous);
        }
        $this->assertSame('Bearer env-key', $mock->request()->getHeaderLine('Authorization'));
        $this->assertSame('https://example.test/v1/messages', (string) $mock->request()->getUri());
    }

    public function testMessagesSendWithIdempotencyKey(): void
    {
        [$honkio, $mock] = $this->with(['id' => 'msg_1', 'status' => 'queued']);
        $result = $honkio->messages->send(
            ['from' => '+14165550100', 'to' => '+16135550123', 'body' => 'Hi', 'media_url' => null],
            'order-1042',
        );

        $this->assertSame(['id' => 'msg_1', 'status' => 'queued'], $result);
        $this->assertRequest($mock, 'POST', '/v1/messages');
        $this->assertSame(['from' => '+14165550100', 'to' => '+16135550123', 'body' => 'Hi'], $mock->jsonBody());
        $this->assertSame('order-1042', $mock->request()->getHeaderLine('Idempotency-Key'));
    }

    public function testMessagesGetAndList(): void
    {
        [$honkio, $mock] = MockHttp::honkio([MockHttp::json(200, ['id' => 'm']), MockHttp::json(200, ['data' => []])]);
        $honkio->messages->get('m');
        $honkio->messages->list(['limit' => 5, 'status' => 'failed']);

        $this->assertRequest($mock, 'GET', '/v1/messages/m');
        $this->assertRequest($mock, 'GET', '/v1/messages?limit=5&status=failed', 1);
    }

    public function testNoConsentIsAnUnprocessableEntity(): void
    {
        [$honkio] = $this->with(['code' => 'NO_CONSENT', 'message' => 'No consent on file.'], 422);
        try {
            $honkio->messages->send(['from' => '+1', 'to' => '+2', 'body' => 'x']);
            $this->fail('expected an exception');
        } catch (UnprocessableEntityException $e) {
            $this->assertSame('NO_CONSENT', $e->errorCode);
            $this->assertSame(422, $e->getCode());
            $this->assertInstanceOf(HonkioException::class, $e);
        }
    }

    public function testPhoneNumbers(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, []),
            MockHttp::json(200, ['id' => 'n1']),
            MockHttp::json(200, ['id' => 'n1']),
            new Response(204),
        ]);
        $honkio->phoneNumbers->areaCodes();
        $honkio->phoneNumbers->search(['area_codes' => ['416', '647'], 'type' => 'local']);
        $honkio->phoneNumbers->provision(['phone_number' => '+14165550100']);
        $honkio->phoneNumbers->get('n1');
        $this->assertNull($honkio->phoneNumbers->release('n1'));

        $this->assertRequest($mock, 'GET', '/v1/phone-numbers/area-codes');
        $this->assertRequest($mock, 'GET', '/v1/phone-numbers/search?area_codes=416%2C647&type=local', 1);
        $this->assertRequest($mock, 'POST', '/v1/phone-numbers', 2);
        $this->assertSame(['phone_number' => '+14165550100'], $mock->jsonBody(2));
        $this->assertRequest($mock, 'DELETE', '/v1/phone-numbers/n1', 4);
    }

    public function testPhoneNumbersListIsAGet(): void
    {
        [$honkio, $mock] = $this->with(['data' => []]);
        $honkio->phoneNumbers->list();
        $this->assertRequest($mock, 'GET', '/v1/phone-numbers');
    }

    public function testVerify(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 'v1']),
            MockHttp::json(200, ['status' => 'approved']),
            MockHttp::json(200, ['id' => 'v1']),
            MockHttp::json(200, ['data' => []]),
        ]);
        $honkio->verify->start(['to' => '+16135550123']);
        $honkio->verify->check('v1', ['code' => '123456']);
        $honkio->verify->get('v1');
        $honkio->verify->list(['limit' => 2]);

        $this->assertRequest($mock, 'POST', '/v1/verify');
        $this->assertRequest($mock, 'POST', '/v1/verify/v1/check', 1);
        $this->assertSame(['code' => '123456'], $mock->jsonBody(1));
        $this->assertRequest($mock, 'GET', '/v1/verify/v1', 2);
        $this->assertRequest($mock, 'GET', '/v1/verify?limit=2', 3);
    }

    public function testConsents(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 'c1']),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['batch_id' => 'b1']),
            MockHttp::json(200, ['has_consent' => true]),
            MockHttp::json(200, ['has_consent' => false]),
        ]);
        $honkio->consents->create(['phone_number' => '+16135550123', 'source' => 'web form']);
        $honkio->consents->list(['limit' => 10]);
        $honkio->consents->recordBatch(['consents' => [['phone_number' => '+16135550123']]]);
        $this->assertSame(['has_consent' => true], $honkio->consents->check(['phone_number' => '+16135550123']));
        $honkio->consents->check(['email_address' => 'a@b.ca']);

        $this->assertRequest($mock, 'POST', '/v1/compliance/consents');
        $this->assertRequest($mock, 'GET', '/v1/compliance/consents?limit=10', 1);
        $this->assertRequest($mock, 'POST', '/v1/compliance/consents/batch', 2);
        $this->assertRequest($mock, 'GET', '/v1/compliance/consents/check?phone_number=%2B16135550123', 3);
        $this->assertRequest($mock, 'GET', '/v1/compliance/consents/check?email_address=a%40b.ca', 4);
    }

    public function testRevokeReturnsNullAndEncodesTheSubject(): void
    {
        [$honkio, $mock] = MockHttp::honkio([new Response(204), new Response(204)]);
        $this->assertNull($honkio->consents->revoke(['phone_number' => '+14165550100']));
        $this->assertNull($honkio->consents->revoke(['email_address' => 'a@b.ca']));

        $this->assertRequest($mock, 'DELETE', '/v1/compliance/consents/%2B14165550100');
        $this->assertRequest($mock, 'DELETE', '/v1/compliance/consents/email/a%40b.ca', 1);
        $this->assertSame('', $mock->request()->getHeaderLine('Content-Type'));
    }

    public function testConsentSubjectNeedsExactlyOneField(): void
    {
        [$honkio, $mock] = $this->with();
        foreach ([[], ['phone_number' => '+1', 'email_address' => 'a@b.ca'], ['phone_number' => ''], ['email_address' => null]] as $subject) {
            try {
                $honkio->consents->revoke($subject);
                $this->fail('expected an exception');
            } catch (InvalidArgumentException) {
            }
            try {
                $honkio->consents->check($subject);
                $this->fail('expected an exception');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, $mock->count());
    }

    public function testConsentBatches(): void
    {
        [$honkio, $mock] = MockHttp::honkio([MockHttp::json(200, ['data' => []]), MockHttp::json(200, ['id' => 'b1'])]);
        $honkio->consentBatches->list(['page' => 2, 'limit' => 5]);
        $honkio->consentBatches->get('b1');

        $this->assertRequest($mock, 'GET', '/v1/compliance/consent-batches?page=2&limit=5');
        $this->assertRequest($mock, 'GET', '/v1/compliance/consent-batches/b1', 1);
    }

    public function testOptInConfirmations(): void
    {
        [$honkio, $mock] = MockHttp::honkio([MockHttp::json(200, ['id' => 'o1']), MockHttp::json(200, ['data' => []])]);
        $honkio->optInConfirmations->send(['phone_number' => '+16135550123']);
        $honkio->optInConfirmations->list(['status' => 'pending']);

        $this->assertRequest($mock, 'POST', '/v1/compliance/opt-in-confirmations');
        $this->assertRequest($mock, 'GET', '/v1/compliance/opt-in-confirmations?status=pending', 1);
    }

    public function testWebhooks(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['id' => 'w1', 'secret' => 's']),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['id' => 'w1']),
            MockHttp::json(200, ['id' => 'w1']),
            new Response(204),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['data' => []]),
            MockHttp::json(200, ['ok' => true]),
            new Response(204),
            MockHttp::json(200, ['ok' => true]),
            MockHttp::json(200, ['secret' => 'new']),
        ]);
        $honkio->webhooks->create(['url' => 'https://example.com/hook', 'events' => ['message.delivered']]);
        $honkio->webhooks->list();
        $honkio->webhooks->get('w1');
        $honkio->webhooks->update('w1', ['active' => false]);
        $this->assertNull($honkio->webhooks->remove('w1'));
        $honkio->webhooks->deliveries('w1', ['limit' => 3]);
        $honkio->webhooks->deadLetters('w1');
        $honkio->webhooks->replay('dl1');
        $this->assertNull($honkio->webhooks->discard('dl1'));
        $honkio->webhooks->reactivate('w1');
        $honkio->webhooks->rotateSecret('w1');

        $expected = [
            ['POST', '/v1/webhooks'], ['GET', '/v1/webhooks'], ['GET', '/v1/webhooks/w1'], ['PATCH', '/v1/webhooks/w1'],
            ['DELETE', '/v1/webhooks/w1'], ['GET', '/v1/webhooks/w1/deliveries?limit=3'], ['GET', '/v1/webhooks/w1/dead-letters'],
            ['POST', '/v1/webhooks/dead-letters/dl1/replay'], ['DELETE', '/v1/webhooks/dead-letters/dl1'],
            ['POST', '/v1/webhooks/w1/reactivate'], ['POST', '/v1/webhooks/w1/rotate-secret'],
        ];
        foreach ($expected as $i => [$method, $uri]) {
            $this->assertRequest($mock, $method, $uri, $i);
        }
        $this->assertSame(['active' => false], $mock->jsonBody(3));
    }

    public function testTollFreeVerifications(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            MockHttp::json(200, ['x' => 1]),
            MockHttp::json(200, ['x' => 1]),
            MockHttp::json(200, ['x' => 1]),
            MockHttp::json(200, ['x' => 1]),
            new Response(204),
            MockHttp::json(200, ['x' => 1]),
            MockHttp::json(200, ['data' => []]),
        ]);
        $honkio->tollFreeVerifications->options();
        $honkio->tollFreeVerifications->create(['phone_number_id' => 'n1']);
        $honkio->tollFreeVerifications->update('t1', ['business_name' => 'Acme']);
        $honkio->tollFreeVerifications->submit('t1');
        $this->assertNull($honkio->tollFreeVerifications->cancel('t1'));
        $honkio->tollFreeVerifications->get('t1');
        $honkio->tollFreeVerifications->list(['status' => 'draft']);

        $expected = [
            ['GET', '/v1/toll-free-verifications/options'], ['POST', '/v1/toll-free-verifications'],
            ['PATCH', '/v1/toll-free-verifications/t1'], ['POST', '/v1/toll-free-verifications/t1/submit'],
            ['DELETE', '/v1/toll-free-verifications/t1'], ['GET', '/v1/toll-free-verifications/t1'],
            ['GET', '/v1/toll-free-verifications?status=draft'],
        ];
        foreach ($expected as $i => [$method, $uri]) {
            $this->assertRequest($mock, $method, $uri, $i);
        }
    }

    public function testBusinessVerificationAutoTopupAndTopupCapRequests(): void
    {
        [$honkio, $mock] = MockHttp::honkio([
            new Response(200, [], 'null'),
            MockHttp::json(200, ['status' => 'draft']),
            MockHttp::json(200, ['status' => 'pending']),
            MockHttp::json(200, ['enabled' => false]),
            MockHttp::json(200, ['id' => 'r1']),
            MockHttp::json(200, ['data' => []]),
        ]);
        $this->assertNull($honkio->businessVerification->get());
        $honkio->businessVerification->save(['legal_name' => 'Acme']);
        $honkio->businessVerification->submit();
        $honkio->autoTopup->get();
        $honkio->topupCapRequests->create(['requested_cap_cents' => 100000]);
        $honkio->topupCapRequests->list();

        $expected = [
            ['GET', '/v1/business-verification'], ['PUT', '/v1/business-verification'],
            ['POST', '/v1/business-verification/submit'], ['GET', '/v1/auto-topup'],
            ['POST', '/v1/account/topup-cap-requests'], ['GET', '/v1/account/topup-cap-requests'],
        ];
        foreach ($expected as $i => [$method, $uri]) {
            $this->assertRequest($mock, $method, $uri, $i);
        }
        $this->assertSame(['legal_name' => 'Acme'], $mock->jsonBody(1));
    }
}
