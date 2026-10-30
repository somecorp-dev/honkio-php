# Changelog

Versions are published to Packagist from a read-only mirror by hand; a version without a date is not published yet.

## 0.3.0

### Added

- Honkio Auth (not available yet: the routes answer 404 until your account is enabled). `$honkio->authApps`: `create`, `list` (cursor pages), `get`, `update` (now also the sign-in methods, `passkey_scope`, `sms_from_number_id`, `send_from_email_domain_id`), `remove`, `rotateSecret`, `verifyDomain`, `uploadLogo($id, $bytes, $contentType)` (multipart), `removeLogo`, `signIns`, `signInPolicy`, `updateSignInPolicy`, and the nested `security`, `plan`, `organizations` (roles upserted with `putRole`), `invitations`, `users` (with `block`, `unblock`, `removeLikelyBots`, `sessions`, `signIns`, `revokeSession`) and `exports` (`create($id, $stepUpToken)`, `download($tokenOrUrl)`). Listed in `Honkio\Endpoints::NOT_LAUNCHED` until launch.
- `Honkio\Auth\HonkioAuth`, for apps that sign users in with Honkio Auth: `verifyIdToken($jwt, $nonce)` and `verifyAccessToken($jwt)` check an ES256 signature against the app's published keys (cached, refetched when a token names a new key), the exact issuer, the app's client id as audience, the token type (`at+jwt` for access tokens) and the time claims; a failure throws `Honkio\Exception\TokenVerificationException` with a `reason`. `refresh(TokenSet)` applies the outage rule: a session is kept while Honkio can't be reached (a connection error, a timeout, a 5xx, a 429), for at most 24 hours past the access token's expiry, and ends as soon as Honkio refuses the refresh. `tokenSetFromResponse()` and `logoutUrl()` help with the code exchange and sign-out. Keys and discovery live in a `Honkio\Auth\Cache` you can share across requests; signed-in users stay signed in on cached keys during an outage. Needs `ext-openssl`.
- `Honkio\Exception\IssuerUnavailableException`: the app's issuer could not be reached and nothing usable was cached.

## 0.2.0 (2026-10-17)

### Added

- Automatic retries, as in `@honkio/node` 0.8.0. `NUMBER_RATE_LIMITED`, `CARRIER_THROTTLED` and `CARRIER_UNAVAILABLE` are retried after the API's `Retry-After` (or `details['retry_after_seconds']`), or an exponential backoff with jitter when it names none; `RATE_LIMITED` and a `ConnectionException` only on a GET or a send with an idempotency key. Never retried: `CARRIER_TIMEOUT`, `DAILY_LIMIT_REACHED`, `FANOUT_LIMIT_REACHED`, `RECIPIENT_RATE_LIMITED`, `SENDING_PAUSED`, `NUMBER_QUEUE_FULL`, or any validation, consent or authentication error. Options `max_retries` (default 2; 0 turns retrying off) and `max_retry_delay` (default 60 seconds; a longer wait is not waited out). When retrying does not help, the last exception is thrown as usual.
- `messages->send`, `emails->send` and `batch->send` always send an `Idempotency-Key`: the one you pass, or else a generated UUID that stays the same on each retry of that call.
- `CARRIER_THROTTLED` (503, an `InternalServerException`): the carrier asked HonkIO to slow down and did not take the message. Nothing was charged.
- `sendLimit->get()` (`GET /v1/send-limit`): the daily cap and usage, per-recipient caps, probation, any pause, past volume requests, and `number_queues`, each number's carrier queue (`phone_number`, `parts_per_minute`, `queued_parts`, `clears_at`).
- A `Retry-After` given as an HTTP date is waited out until that time, as well as one given in seconds.
- `messages->send` returns `estimated_send_at` beside the message's fields: an estimate of when the carrier will start sending it (an ISO 8601 string; `null` in test mode and on a retry that returned an earlier send).

## 0.1.0 (2026-10-14)

First release, with the coverage of `@honkio/node` 0.7.0.

### Added

- SMS: `messages`, `phoneNumbers`, `tollFreeVerifications`, `optInConfirmations`, `verify`, `consents`, `consentBatches`.
- Email: `emails` (including `emails->received`), `batch`, `domains`, `suppressions`, `templates`.
- Account: `businessVerification`, `autoTopup`, `topupCapRequests`, `webhooks`.
- `Honkio\Webhook::verify()` and `Honkio\WebhookEvents::ALL`.
- Errors are exceptions, all implementing `Honkio\Exception\HonkioException`: `ApiException` with a subclass per HTTP status, `ConnectionException`, `InvalidArgumentException`, `SignatureVerificationException`.
- `Honkio\Endpoints::ALL`, the route table, checked against the API's OpenAPI snapshot by the test suite.
