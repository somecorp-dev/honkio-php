# Changelog

Versions are published to Packagist from a read-only mirror by hand; a version without a date is not published yet.

## 0.1.0 (2026-10-14)

First release, with the coverage of `@honkio/node` 0.7.0.

### Added

- SMS: `messages`, `phoneNumbers`, `tollFreeVerifications`, `optInConfirmations`, `verify`, `consents`, `consentBatches`.
- Email: `emails` (including `emails->received`), `batch`, `domains`, `suppressions`, `templates`.
- Account: `businessVerification`, `autoTopup`, `topupCapRequests`, `webhooks`.
- `Honkio\Webhook::verify()` and `Honkio\WebhookEvents::ALL`.
- Errors are exceptions, all implementing `Honkio\Exception\HonkioException`: `ApiException` with a subclass per HTTP status, `ConnectionException`, `InvalidArgumentException`, `SignatureVerificationException`.
- `Honkio\Endpoints::ALL`, the route table, checked against the API's OpenAPI snapshot by the test suite.
- Automatic retries, as in `@honkio/node` 0.8.0. `NUMBER_RATE_LIMITED`, `CARRIER_THROTTLED` and `CARRIER_UNAVAILABLE` are retried after the API's `Retry-After` (or `details['retry_after_seconds']`), or an exponential backoff with jitter when it names none; `RATE_LIMITED` and a `ConnectionException` only on a GET or a send with an idempotency key. Never retried: `CARRIER_TIMEOUT`, `DAILY_LIMIT_REACHED`, `FANOUT_LIMIT_REACHED`, `RECIPIENT_RATE_LIMITED`, `SENDING_PAUSED`, `NUMBER_QUEUE_FULL`, or any validation, consent or authentication error. Options `max_retries` (default 2; 0 turns retrying off) and `max_retry_delay` (default 60 seconds; a longer wait is not waited out). When retrying does not help, the last exception is thrown as usual.
- `messages->send`, `emails->send` and `batch->send` always send an `Idempotency-Key`: the one you pass, or else a generated UUID that stays the same on each retry of that call.
- `CARRIER_THROTTLED` (503, an `InternalServerException`): the carrier asked HonkIO to slow down and did not take the message. Nothing was charged.
