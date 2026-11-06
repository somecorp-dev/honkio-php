# Changelog

Versions are published to Packagist from a read-only mirror by hand; a version without a date is not published yet.

## Unreleased

### Added

- Sending from a stored template on every channel: `messages->send()` and `push->send()` take `template` (a template's id or alias), `variables` and `language` (`en` or `fr`) in place of their own text, and send that template's SMS or push part, rendered. `emails->send()` and `batch->send()` take `language` next to `template`. An empty `variables` array is sent as a JSON object. A language without the part uses the template's default language's; a template with no part for the channel answers `TEMPLATE_CHANNEL_MISSING`. A retry with the same Idempotency-Key replays a template SMS even after the template was changed, republished or deleted; the same key with another template, other variables or another language is `IDEMPOTENCY_KEY_REUSED`. The match is strict: the template's id and its alias, `1` and `"1"` as a variable value, and a language left out and the default language named are different requests. A group send's members are bound the same way: a live group send retried within 24 hours is answered with its first response as before, and a test-mode group send, or a live one retried after those 24 hours, replays its members or answers `IDEMPOTENCY_KEY_REUSED` for different variables.
- **Breaking:** `templates` now manages all templates (`/v1/templates`): `create()`, `get()`, `list()`, `update()`, `publish()`, `rollback()`, `versions()` and `remove()` for templates with an email, SMS and push part in English, French or both, with a `default_language`. This is how to make an SMS-only, push-only or French template from code. The email-only resource (`/v1/email-templates`, which shows only templates with an email part) is now `emailTemplates` (deprecated), with the same methods as before. The `parts` shape (per language, `email`, `sms` and `push`, each a part or `null`) is declared as PHPStan array shapes on `create()` and `update()`; an empty `parts` array is sent as a JSON object.

### Changed

- **Breaking:** push payload encryption now uses a key per device instead of one per app. `push->encryption` (`set()`, `listKeys()`, `createKey()`, `activateKey()`, the per-app key resource, class `PushEncryption`) is removed: push is not launched yet. `push->devices->register()` takes `encryption_public_key` (the public key the HonkIO Swift or Android push package generated on the device; `null` removes the stored key) and the device it returns has `encryption_key_id`. `push->apps->create()` and `push->apps->update()` take `require_encryption`, and a push app has `require_encryption` in place of `encryption_enabled`: `true` refuses a device without a key with `ENCRYPTION_KEY_MISSING`, never sent in plaintext and never charged. Each delivery gains `encrypted`, and a push's `encrypted` is now the app's `require_encryption` when it was sent.
- `messages->send()` with neither `body` nor `template` still throws `UnprocessableEntityException` (`VALIDATION_ERROR`), but its `details` is now `['formErrors' => [...], 'fieldErrors' => ['body' => [...]]]` rather than a list of JSON Schema errors. `variables` or `language` without a template, or a template together with `body` (or with a push's `title` or `body`), is now refused with `VALIDATION_ERROR`; before, the stray keys were dropped.

## 0.4.0

### Added

- Scheduled SMS, as email has it: `$honkio->messages->send([..., 'scheduled_at' => ...])` (an ISO 8601 string or a `DateTimeInterface`, 1 minute to 30 days ahead) accepts the message as `SCHEDULED` and charges it now; `messages->update($id, ['scheduled_at' => ...])` moves it and `messages->cancel($id)` cancels it and refunds the charge. Listed in `Honkio\Endpoints`.
- Scheduled push on the same contract: `push->send([..., 'scheduled_at' => ...])` comes back `scheduled` and is charged now; `push->update($id, [...])` moves it and `push->cancel($id)` cancels it and refunds what it reserved.
- A late-send limit for scheduled SMS and push: `messages->send`, `push->send`, `messages->update` and `push->update` take `expires_at` (an ISO 8601 string or a `DateTimeInterface`, only with `scheduled_at`, 1 minute to 24 hours after it; `null` on `update` resets it to the default). A scheduled send that cannot go out by then (by default `scheduled_at` plus 24 hours) fails with `SCHEDULED_SEND_EXPIRED` and is refunded. A due scheduled SMS the carrier throttles or cannot reach is tried again until then instead of failing.
- `WebhookEvents::ALL` gains `message.cancelled`, `message.rescheduled`, `push.cancelled` and `push.rescheduled`.

### Changed

- The `push->keys` doc comments say the active and next keys, and that making the next key active retires the previous one.

## 0.3.1 (2026-11-02)

### Changed

- Accepts `guzzlehttp/guzzle` 8 as well as 7 (`^7.8 || ^8.0`).

### Security

- `var_dump`, `print_r` and `debug_zval_dump` no longer show the API key: `Honkio` and `Honkio\Http\HttpClient` answer with the base URL and `[redacted]`. `Honkio\Auth\HonkioAuth` and `Honkio\Auth\TokenRefresher` do the same for the client secret, and leave out the cache (which holds refreshed tokens).
- The API key and the client secret are marked `#[\SensitiveParameter]` on the constructors that take them (`Honkio`, `HttpClient`, `HonkioAuth`, `TokenRefresher`), so a stack trace shows `Object(SensitiveParameterValue)` in their place.

## 0.3.0 (2026-10-31)

### Added

- HonkIO Auth. `$honkio->authApps`: `create`, `list` (cursor pages), `get`, `update` (now also the sign-in methods, `passkey_scope`, `sms_from_number_id`, `send_from_email_domain_id`, and a `hostname` sent on its own, which moves the app to a new hostname once it is proven), `cancelHostnameChange`, `remove`, `rotateSecret`, `verifyDomain`, `uploadLogo($id, $bytes, $contentType)` (multipart), `removeLogo`, `signIns`, `signInPolicy`, `updateSignInPolicy`, and the nested `security`, `plan`, `organizations` (roles upserted with `putRole`), `invitations`, `users` (with `block`, `unblock`, `removeLikelyBots`, `sessions`, `signIns`, `passkeys`, `removePasskey`, `revokeSession`) and `exports` (`create($id, $stepUpToken)`, `get`, `latest($id, $stepUpToken)`, which with the step-up answers a fresh download link to the key that requested a ready export, and `download($tokenOrUrl)`). Listed in `Honkio\Endpoints::AUTH` (`NOT_LAUNCHED` is its deprecated old name).
- `Honkio\Auth\HonkioAuth`, for apps that sign users in with Honkio Auth: `verifyIdToken($jwt, $nonce)` and `verifyAccessToken($jwt)` check an ES256 signature against the app's published keys (cached, refetched when a token names a new key), the exact issuer, the app's client id as audience, the token type (`at+jwt` for access tokens) and the time claims; a failure throws `Honkio\Exception\TokenVerificationException` with a `reason`. `refresh(TokenSet)` applies the outage rule: a session is kept while Honkio can't be reached (a connection error, a timeout, a 5xx), for at most 24 hours past the access token's expiry, and ends as soon as Honkio refuses the refresh; a 429 or 408 keeps the tokens for that request only (`kept`, reason `busy`). A refresh's result is handed to a request with the same old refresh token for 2 seconds (`remember_refreshes` turns this off). Once 3 different sessions in a row can't reach an app's issuer within 10 seconds, that app's refreshes keep their sessions without calling Honkio for 15 seconds, and its cached keys and discovery are used without refetching; the pause is kept in the cache per issuer and client id, so it is shared by your PHP processes and never touches another app, and a 429 or 408 never starts it. `revoke($refreshToken)` ends the session at Honkio (RFC 7009) for sign-out, after deleting the refresh memo for that token. `tokenSetFromResponse()` and `logoutUrl()` help with the code exchange and sign-out. Keys and discovery live in a `Honkio\Auth\Cache` you can share across requests; signed-in users stay signed in on cached keys during an outage. Needs `ext-openssl`.
- `Honkio\Exception\IssuerUnavailableException`: the app's issuer could not be reached and nothing usable was cached.
- `Honkio\Auth\DeletableCache`: a `Cache` with `delete($key)`, which `revoke()` uses; a cache that implements only `Cache` keeps working (the entries are overwritten with an empty value that expires at once). `InMemoryCache` implements it.

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
