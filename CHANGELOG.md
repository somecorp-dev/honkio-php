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
