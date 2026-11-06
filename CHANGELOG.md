# Changelog

Versions are published to Packagist from a read-only mirror by hand; a version without a date is not published yet.

## Unreleased

### Added

- Sending from a stored template on every channel: `messages->send()` and `push->send()` take `template` (a template's id or alias), `variables` and `language` (`en` or `fr`) in place of their own text, and send that template's SMS or push part, rendered. `emails->send()` and `batch->send()` take `language` next to `template`. An empty `variables` array is sent as a JSON object. A language without the part uses the template's default language's; a template with no part for the channel answers `TEMPLATE_CHANNEL_MISSING`.

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
