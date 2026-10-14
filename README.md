# honkio/honkio-php

The official PHP SDK for [HonkIO](https://honkio.ca): Canadian SMS and email, with CASL built in. Data is stored in Canada (ca-central-1).

- PHP 8.2 or newer, [Guzzle](https://docs.guzzlephp.org) 7 (any `GuzzleHttp\ClientInterface` can be injected)
- Requests are associative arrays in the API's own snake_case; responses are decoded associative arrays
- Errors are exceptions, and every one of them implements `Honkio\Exception\HonkioException`
- No automatic retries: a send costs money, so retrying is your choice, with an idempotency key
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

`new Honkio()` with no argument reads `HONKIO_API_KEY`, and throws `InvalidArgumentException` if neither is set. Options: `new Honkio($key, ['base_url' => ..., 'timeout' => 30, 'http_client' => $guzzle])`.

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

## Idempotency

`messages->send`, `emails->send` and `batch->send` take an optional idempotency key as their last argument and send it as the `Idempotency-Key` header. After a `ConnectionException`, retry with the same key and the send happens at most once.

```php
$honkio->emails->send($params, idempotencyKey: 'welcome-user-42');
```

## Request shapes

Parameters are arrays with the API's snake_case keys (`scheduled_at`, `is_commercial`, `reply_to`). A key you leave out is not sent; a key set to `null` is sent as JSON null, which clears a nullable field (`$honkio->templates->update('welcome', ['html' => null])`). In query parameters `null` means "not given" and is left out. A `DateTimeInterface` is sent as ISO 8601 in UTC. Query lists repeat the key (`['status' => ['a', 'b']]` becomes `status=a&status=b`). Ids and other path values are separate string arguments: `$honkio->webhooks->update($id, ['active' => false])`. Deletes return `null`.

## Resources

| Property | Methods |
|---|---|
| `messages` (SMS) | `send($params, $idempotencyKey)`, `get($id)`, `list($query)` |
| `phoneNumbers` | `areaCodes()`, `search($query)` (`area_codes` may be a list), `provision($params)` (live keys; charges your balance), `list()`, `get($id)`, `release($id)` |
| `tollFreeVerifications` | `options()`, `create($params)`, `update($id, $params)`, `submit($id)`, `cancel($id)`, `get($id)`, `list($query)` |
| `businessVerification` | `get()` (null before a profile is saved), `save($params)`, `submit()` |
| `autoTopup` | `get()`: settings, saved card summary, pause state. Read-only |
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

The tests read `web/generated/openapi.json` in the HonkIO monorepo and fail when a route the SDK calls is missing from the API.

## License

MIT
