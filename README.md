# honkio/honkio-php

The official PHP SDK for [HonkIO](https://honkio.ca): Canadian SMS and email, with CASL built in. Data is stored in Canada (ca-central-1).

- PHP 8.2 or newer, [Guzzle](https://docs.guzzlephp.org) 7 (any `GuzzleHttp\ClientInterface` can be injected)
- Requests are associative arrays in the API's own snake_case; responses are decoded associative arrays
- Errors are exceptions, and every one of them implements `Honkio\Exception\HonkioException`
- Retries what waiting fixes (a number's per-minute sending rate, the carrier asking to slow down), with an idempotency key so a retried send never goes out twice
- Live sending and live receiving (buying a number, the managed inbound email address, a domain's receiving toggle) need the account owner's phone verified once; test keys skip it

On Laravel? Use [`honkio/laravel`](https://honkio.ca/docs/sdk) for a mail transport and an SMS notification channel.

## Install

```sh
composer require honkio/honkio-php
```

## Quickstart

Create a key in the dashboard under API Keys, Create key (<https://honkio.ca/dashboard/keys>); the full key is shown once, right after you create it. Start with a test key (`mk_test_...`): nothing is delivered or charged.

### Send an SMS

```php
<?php

use Honkio\Exception\ApiException;
use Honkio\Honkio;

$honkio = new Honkio(getenv('HONKIO_API_KEY'));

try {
    $message = $honkio->messages->send([
        'from' => '+1416XXXXXXX', // one of your HonkIO numbers
        'to' => '+1613XXXXXXX',   // a Canadian number you hold consent for
        'body' => 'Hello from HonkIO!',
    ], idempotencyKey: 'order-1042');

    echo $message['id'], ' ', $message['status'], "\n";
} catch (ApiException $e) {
    echo $e->errorCode, ': ', $e->getMessage(), "\n"; // e.g. NO_CONSENT
}
```

`new Honkio()` with no argument reads `HONKIO_API_KEY`, and throws `InvalidArgumentException` if neither is set. Options: `new Honkio($key, ['base_url' => ..., 'timeout' => 30, 'http_client' => $guzzle, 'max_retries' => 2, 'max_retry_delay' => 60])`; see [Retries](#retries).

### Send an email

```php
$email = $honkio->emails->send([
    'from' => 'Acme <onboarding@test.honkio.ca>',
    'to' => 'delivered@test.honkio.ca',
    'subject' => 'Hello from HonkIO',
    'html' => '<p>It works.</p>',
]);
```

Email is transactional by default. Marketing email must set `'is_commercial' => true`: it needs CASL consent on file for the recipient, goes to exactly one recipient, and carries an unsubscribe footer. Transactional email also reaches addresses that unsubscribed, so a promotion sent without the flag would get around the unsubscribe.

## Errors

Every exception implements `Honkio\Exception\HonkioException`, so `catch (HonkioException $e)` catches them all.

| Exception | When |
|---|---|
| `ApiException` and its subclasses | The API refused the request. `$e->errorCode` is the API's code (`NO_CONSENT`), `$e->statusCode` the HTTP status (also `getCode()`), `$e->getMessage()` its message, `$e->details` its details |
| `ConnectionException` | No response arrived (DNS, refused connection, timeout). The send may or may not have happened |
| `InvalidArgumentException` | A caller mistake caught before anything was sent: no API key, an empty or `.` or `..` id, a consent call naming both or neither subject, a missing webhook secret |
| `SignatureVerificationException` | A webhook could not be verified; see [Webhooks](#webhooks) |

`ApiException` subclasses by status: `BadRequestException` (400), `AuthenticationException` (401), `PaymentRequiredException` (402), `PermissionDeniedException` (403), `NotFoundException` (404), `ConflictException` (409), `UnprocessableEntityException` (422), `RateLimitException` (429), `UnavailableForLegalReasonsException` (451), `InternalServerException` (5xx). A response whose body is not JSON (a proxy error page, say) has `errorCode` `application_error`.

`details` is the API's `details` when it sent one (validation paths, `retry_after`, missing template keys). Otherwise it holds whatever the API sent beside the error envelope: `['attempts_remaining' => 4]` on `VERIFICATION_INVALID_CODE`.

```php
try {
    $honkio->verify->check($id, ['code' => '123456']);
} catch (Honkio\Exception\ApiException $e) {
    if ($e->errorCode === 'VERIFICATION_INVALID_CODE') {
        echo $e->details['attempts_remaining'];
    }
}
```

The general codes and every SMS and email code are listed at <https://honkio.ca/docs/errors>.

## Retries

The client sends a call again when the API refused it only because it should wait:

| `errorCode` | Retried |
|---|---|
| `NUMBER_RATE_LIMITED` (429) | always: the sending number already has a minute of message parts waiting at the carrier (a local number sends 10 parts a minute) |
| `CARRIER_THROTTLED` (503) | always: the carrier asked HonkIO to slow down and did not take the message; nothing was charged |
| `CARRIER_UNAVAILABLE` (503) | always: the carrier could not be reached; nothing was sent or charged |
| `RATE_LIMITED` (429) | on a GET or a send with an idempotency key. Elsewhere it can be the route's own limit, such as one verification start per recipient a minute, where a repeat would send again |
| `ConnectionException` | on a GET or a send with an idempotency key: the request may have reached the API |

Each retry waits as long as the API's `Retry-After` header (seconds, or an HTTP date to wait until) or `details['retry_after_seconds']` says, or backs off exponentially with jitter when it says nothing. By default a call is retried at most 2 times, and a wait longer than 60 seconds is not waited out: the exception is thrown at once. When retrying does not help, the last exception is thrown, the same as without retries. The client waits with `usleep()`, so a retry blocks the PHP process for the wait.

```php
$honkio = new Honkio($key, ['max_retries' => 4, 'max_retry_delay' => 30]);
$noRetries = new Honkio($key, ['max_retries' => 0]);
```

Never retried: `CARRIER_TIMEOUT` (the message may have gone out: check its status with `messages->get()` before sending again), `DAILY_LIMIT_REACHED`, `FANOUT_LIMIT_REACHED`, `RECIPIENT_RATE_LIMITED`, `SENDING_PAUSED`, `NUMBER_QUEUE_FULL`, and every validation, consent or authentication error.

A local number sends 10 message parts a minute, so a burst bigger than that is spread out at that pace: each refusal says how long until there is room (about 6 seconds per part waiting). For a list, send to a contact group or pace your sends; for real volume, use a verified toll-free number. Do not spread one list across several numbers to go faster: carriers treat that as snowshoeing and it breaks the acceptable use policy.

### Send limit and number queues

`messages->send()` returns the message with `estimated_send_at`: an ISO 8601 estimate of when the carrier should start sending it, from the parts already waiting on the sending number (`null` in test mode and on a retry that returned an earlier send). `sendLimit->get()` shows the rest before a burst: the daily cap and what is left of it, the per-recipient caps, probation, any pause, past volume requests, and `number_queues`, each number's carrier queue.

```php
$limits = $honkio->sendLimit->get();
echo $limits['remaining'], ' of ', $limits['daily_limit'], " left today\n";
foreach ($limits['number_queues'] as $queue) {
    // parts_per_minute is 10 for a local number; clears_at is null when nothing is waiting.
    echo $queue['phone_number'], ' ', $queue['queued_parts'], ' ', $queue['parts_per_minute'], ' ', $queue['clears_at'] ?? '-', "\n";
}
```

## Idempotency

`messages->send`, `emails->send` and `batch->send` always send an `Idempotency-Key` header: the key you pass as their last argument, otherwise a generated UUID that stays the same on each retry of that call. The API answers a repeated key with the original result, so a retry can never send or charge twice.

The generated key covers only the client's own retries. If your code sends again after an exception (a queued job that runs again, say), pass your own key, derived from what is being sent (an order id), so the API recognises the repeat.

```php
$honkio->emails->send($params, idempotencyKey: 'welcome-user-42');
```

## Request shapes

Parameters are arrays with the API's snake_case keys (`scheduled_at`, `is_commercial`, `reply_to`). A key you leave out is not sent; a key set to `null` is sent as JSON null, which clears a nullable field (`$honkio->templates->update('welcome', ['html' => null])`). In query parameters `null` means "not given" and is left out. A `DateTimeInterface` is converted to UTC and sent as ISO 8601 with a `Z` suffix (`2026-10-14T16:30:00.000Z`). The fields the API declares as plain dates (`date_from` and `date_to` on `messages->list`, `last_transaction_date` on `consents->create`) take a `YYYY-MM-DD` string; a `DateTimeInterface` passed there is sent as its UTC date.

An empty array for an object-typed field is sent as `[]`, not `{}`; pass `new \stdClass()` for an empty object. The one exception is `emails->send` and `batch->send`, where an empty `variables` or `headers` map is always sent as `{}`. (On `templates` and `emails->received->simulate` the same keys are lists, and stay lists.)

Query lists repeat the key (`['status' => ['a', 'b']]` becomes `status=a&status=b`). Ids and other path values are separate string arguments: `$honkio->webhooks->update($id, ['active' => false])`. Deletes return `null`.

## Resources

| Property | Methods |
|---|---|
| `messages` (SMS) | `send($params, $idempotencyKey)` (the message plus `estimated_send_at`), `get($id)`, `list($query)` |
| `phoneNumbers` | `areaCodes()`, `search($query)` (`area_codes` may be a list), `provision($params)` (live keys; charges your balance), `list()`, `get($id)`, `release($id)` |
| `tollFreeVerifications` | `options()`, `create($params)`, `update($id, $params)`, `submit($id)`, `cancel($id)`, `get($id)`, `list($query)` |
| `businessVerification` | `get()` (null before a profile is saved), `save($params)`, `submit()` |
| `autoTopup` | `get()`: settings, saved card summary, pause state. Read-only |
| `sendLimit` | `get()`: the daily cap and usage, per-recipient caps, probation, any pause, past volume requests, and `number_queues`; see [Send limit and number queues](#send-limit-and-number-queues) |
| `topupCapRequests` | `create($params)`, `list()` |
| `optInConfirmations` | `send($params)`, `list($query)` |
| `verify` | `start($params)`, `check($id, $params)`, `get($id)`, `list($query)` |
| `consents` | `create($params)`, `recordBatch($params)`, `list($query)`, `check($subject)`, `revoke($subject)` |
| `consentBatches` | `list($query)`, `get($id)` |
| `webhooks` | `create`, `list`, `get`, `update`, `remove`, `deliveries($id, $query)`, `deadLetters($id, $query)`, `replay($deadLetterId)`, `discard($deadLetterId)`, `reactivate($id)`, `rotateSecret($id)`, `verify($rawBody, $headers, $secret)` |
| `emails` | `send($params, $idempotencyKey)`, `get($id)`, `list($query)`, `update($id, $params)`, `cancel($id)` |
| `emails->received` | `list($query)`, `address()`, `setAddressEnabled($enabled)`, `get($id, $query)`, `raw($id)`, `attachment($id, $attachmentId)` (both return the body as a string), `simulate($params)` (test keys only) |
| `batch` | `send($emails, $idempotencyKey)`: a list of up to 100 emails, or `['template' => ..., 'recipients' => [...]]` for up to 500 recipients |
| `domains` | `create($params)`, `get($id)`, `list()`, `update($id, $params)`, `verify($id)`, `remove($id)` |
| `suppressions` | `create(['email_address' => ...])`, `list($query)`, `remove($emailAddress)` |
| `templates` | `create`, `get($idOrAlias)`, `list`, `update`, `publish`, `rollback($idOrAlias, ['version' => 2])`, `versions`, `remove` |

`Honkio\Endpoints::ALL` lists every route the SDK calls as `[resource, method, HTTP method, path]`.

### Consent

`check` and `revoke` take exactly one of `phone_number` or `email_address`; anything else throws `InvalidArgumentException` before a request is sent.

```php
$honkio->consents->create(['phone_number' => '+1613XXXXXXX', 'consent_type' => 'express', 'source_description' => 'Booking form']);
$honkio->consents->check(['phone_number' => '+1613XXXXXXX']);
$honkio->consents->revoke(['email_address' => 'ada@example.ca']); // returns null
```

### Receiving email

```php
$address = $honkio->emails->received->address();
$page = $honkio->emails->received->list(['limit' => 10]);
$mime = $honkio->emails->received->raw($page['data'][0]['id']); // string
```

## Webhooks

Check every delivery before you act on it. Pass the raw request body exactly as received, before any JSON parsing, and the headers.

```php
use Honkio\Exception\SignatureVerificationException;
use Honkio\Webhook;

$rawBody = file_get_contents('php://input');

try {
    $event = Webhook::verify($rawBody, getallheaders(), getenv('HONKIO_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'message.delivered') {
    // ...
}
```

The signature is HMAC-SHA256, keyed with the signing secret as UTF-8 (not hex-decoded), over `"{X-HonkIO-Timestamp}.{raw body}"`. A timestamp more than 300 seconds from now is refused as a possible replay; pass a fourth argument to change that. Header names are matched case-insensitively and a value may be a string or a list of strings, so PSR-7 and Laravel header arrays work as they are. A missing secret throws `InvalidArgumentException`; anything about the headers, signature, timestamp or body throws `SignatureVerificationException`. `Honkio\WebhookEvents::ALL` lists every event a webhook can subscribe to. See <https://honkio.ca/docs/webhooks/signing> for the published test vector.

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Inside the HonkIO monorepo the tests read `web/generated/openapi.json` and fail when a route the SDK calls is missing from the API; in a standalone clone those tests skip.

## Testing

The SDK classes are `final`, so they cannot be mocked. Build a `Honkio` with a Guzzle `MockHandler` instead and bind it where your code expects the client:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"id":"msg_1"}')]);
$honkio = new Honkio\Honkio('test-key', ['http_client' => new Client(['handler' => HandlerStack::create($mock)])]);
```

## License

MIT
