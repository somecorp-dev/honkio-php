<?php

declare(strict_types=1);

namespace Honkio;

/**
 * Every route the SDK calls: [resource, method, HTTP method, path]. ALL is
 * checked against the committed OpenAPI snapshot by tests/SpecContractTest.php;
 * UNCHECKED holds the routes the snapshot leaves out.
 */
final class Endpoints
{
    /** @var list<array{string, string, string, string}> */
    public const ALL = [
        ['messages', 'send', 'POST', '/v1/messages'],
        ['messages', 'get', 'GET', '/v1/messages/{id}'],
        ['messages', 'list', 'GET', '/v1/messages'],
        ['messages', 'update', 'PATCH', '/v1/messages/{id}'],
        ['messages', 'cancel', 'DELETE', '/v1/messages/{id}'],
        ['phoneNumbers', 'areaCodes', 'GET', '/v1/phone-numbers/area-codes'],
        ['phoneNumbers', 'search', 'GET', '/v1/phone-numbers/search'],
        ['phoneNumbers', 'provision', 'POST', '/v1/phone-numbers'],
        ['phoneNumbers', 'list', 'GET', '/v1/phone-numbers'],
        ['phoneNumbers', 'get', 'GET', '/v1/phone-numbers/{id}'],
        ['phoneNumbers', 'release', 'DELETE', '/v1/phone-numbers/{id}'],
        ['verify', 'start', 'POST', '/v1/verify'],
        ['verify', 'check', 'POST', '/v1/verify/{id}/check'],
        ['verify', 'get', 'GET', '/v1/verify/{id}'],
        ['verify', 'list', 'GET', '/v1/verify'],
        ['consents', 'create', 'POST', '/v1/compliance/consents'],
        ['consents', 'list', 'GET', '/v1/compliance/consents'],
        ['consents', 'recordBatch', 'POST', '/v1/compliance/consents/batch'],
        ['consents', 'check', 'GET', '/v1/compliance/consents/check'],
        ['consents', 'revoke', 'DELETE', '/v1/compliance/consents/{phone}'],
        ['consents', 'revoke', 'DELETE', '/v1/compliance/consents/email/{address}'],
        ['consentBatches', 'list', 'GET', '/v1/compliance/consent-batches'],
        ['consentBatches', 'get', 'GET', '/v1/compliance/consent-batches/{id}'],
        ['optInConfirmations', 'send', 'POST', '/v1/compliance/opt-in-confirmations'],
        ['optInConfirmations', 'list', 'GET', '/v1/compliance/opt-in-confirmations'],
        ['webhooks', 'create', 'POST', '/v1/webhooks'],
        ['webhooks', 'list', 'GET', '/v1/webhooks'],
        ['webhooks', 'get', 'GET', '/v1/webhooks/{id}'],
        ['webhooks', 'update', 'PATCH', '/v1/webhooks/{id}'],
        ['webhooks', 'remove', 'DELETE', '/v1/webhooks/{id}'],
        ['webhooks', 'deliveries', 'GET', '/v1/webhooks/{id}/deliveries'],
        ['webhooks', 'deadLetters', 'GET', '/v1/webhooks/{id}/dead-letters'],
        ['webhooks', 'replay', 'POST', '/v1/webhooks/dead-letters/{dlid}/replay'],
        ['webhooks', 'discard', 'DELETE', '/v1/webhooks/dead-letters/{dlid}'],
        ['webhooks', 'reactivate', 'POST', '/v1/webhooks/{id}/reactivate'],
        ['webhooks', 'rotateSecret', 'POST', '/v1/webhooks/{id}/rotate-secret'],
        ['emails', 'send', 'POST', '/v1/emails'],
        ['emails', 'get', 'GET', '/v1/emails/{id}'],
        ['emails', 'list', 'GET', '/v1/emails'],
        ['emails', 'update', 'PATCH', '/v1/emails/{id}'],
        ['emails', 'cancel', 'DELETE', '/v1/emails/{id}'],
        ['emails.received', 'list', 'GET', '/v1/emails/received'],
        ['emails.received', 'address', 'GET', '/v1/emails/received/address'],
        ['emails.received', 'setAddressEnabled', 'PATCH', '/v1/emails/received/address'],
        ['emails.received', 'get', 'GET', '/v1/emails/received/{id}'],
        ['emails.received', 'raw', 'GET', '/v1/emails/received/{id}/raw'],
        ['emails.received', 'attachment', 'GET', '/v1/emails/received/{id}/attachments/{attachmentId}'],
        ['emails.received', 'simulate', 'POST', '/v1/emails/received/simulate'],
        ['batch', 'send', 'POST', '/v1/emails/batch'],
        ['domains', 'create', 'POST', '/v1/email-domains'],
        ['domains', 'get', 'GET', '/v1/email-domains/{id}'],
        ['domains', 'list', 'GET', '/v1/email-domains'],
        ['domains', 'update', 'PATCH', '/v1/email-domains/{id}'],
        ['domains', 'verify', 'POST', '/v1/email-domains/{id}/verify'],
        ['domains', 'remove', 'DELETE', '/v1/email-domains/{id}'],
        ['suppressions', 'create', 'POST', '/v1/email-suppressions'],
        ['suppressions', 'list', 'GET', '/v1/email-suppressions'],
        ['suppressions', 'remove', 'DELETE', '/v1/email-suppressions/{address}'],
        ['templates', 'create', 'POST', '/v1/email-templates'],
        ['templates', 'get', 'GET', '/v1/email-templates/{idOrAlias}'],
        ['templates', 'list', 'GET', '/v1/email-templates'],
        ['templates', 'update', 'PATCH', '/v1/email-templates/{idOrAlias}'],
        ['templates', 'publish', 'POST', '/v1/email-templates/{idOrAlias}/publish'],
        ['templates', 'rollback', 'POST', '/v1/email-templates/{idOrAlias}/rollback'],
        ['templates', 'versions', 'GET', '/v1/email-templates/{idOrAlias}/versions'],
        ['templates', 'remove', 'DELETE', '/v1/email-templates/{idOrAlias}'],
        ['tollFreeVerifications', 'options', 'GET', '/v1/toll-free-verifications/options'],
        ['tollFreeVerifications', 'create', 'POST', '/v1/toll-free-verifications'],
        ['tollFreeVerifications', 'update', 'PATCH', '/v1/toll-free-verifications/{id}'],
        ['tollFreeVerifications', 'submit', 'POST', '/v1/toll-free-verifications/{id}/submit'],
        ['tollFreeVerifications', 'cancel', 'DELETE', '/v1/toll-free-verifications/{id}'],
        ['tollFreeVerifications', 'get', 'GET', '/v1/toll-free-verifications/{id}'],
        ['tollFreeVerifications', 'list', 'GET', '/v1/toll-free-verifications'],
        ['businessVerification', 'get', 'GET', '/v1/business-verification'],
        ['businessVerification', 'save', 'PUT', '/v1/business-verification'],
        ['businessVerification', 'submit', 'POST', '/v1/business-verification/submit'],
        ['autoTopup', 'get', 'GET', '/v1/auto-topup'],
        ['sendLimit', 'get', 'GET', '/v1/send-limit'],
        ['topupCapRequests', 'create', 'POST', '/v1/account/topup-cap-requests'],
        ['topupCapRequests', 'list', 'GET', '/v1/account/topup-cap-requests'],
        ...self::AUTH_APPS,
    ];

    /**
     * HonkIO Auth's management API, part of ALL. tests/AuthEndpointsTest.php
     * also checks these against the API's route files.
     *
     * @var list<array{string, string, string, string}>
     */
    private const AUTH_APPS = [
        ['authApps', 'create', 'POST', '/v1/auth-apps'],
        ['authApps', 'list', 'GET', '/v1/auth-apps'],
        ['authApps', 'get', 'GET', '/v1/auth-apps/{id}'],
        ['authApps', 'update', 'PATCH', '/v1/auth-apps/{id}'],
        ['authApps', 'remove', 'DELETE', '/v1/auth-apps/{id}'],
        ['authApps', 'cancelHostnameChange', 'DELETE', '/v1/auth-apps/{id}/hostname-change'],
        ['authApps', 'rotateSecret', 'POST', '/v1/auth-apps/{id}/rotate-secret'],
        ['authApps', 'verifyDomain', 'POST', '/v1/auth-apps/{id}/domain/verify'],
        ['authApps', 'uploadLogo', 'PUT', '/v1/auth-apps/{id}/logo'],
        ['authApps', 'removeLogo', 'DELETE', '/v1/auth-apps/{id}/logo'],
        ['authApps', 'signIns', 'GET', '/v1/auth-apps/{id}/sign-ins'],
        ['authApps', 'signInPolicy', 'GET', '/v1/auth-apps/{id}/sign-in-policy'],
        ['authApps', 'updateSignInPolicy', 'PATCH', '/v1/auth-apps/{id}/sign-in-policy'],
        ['authApps.security', 'get', 'GET', '/v1/auth-apps/{id}/security'],
        ['authApps.security', 'update', 'PATCH', '/v1/auth-apps/{id}/security'],
        ['authApps.security', 'summary', 'GET', '/v1/auth-apps/{id}/security/summary'],
        ['authApps.security', 'blocklist', 'GET', '/v1/auth-apps/{id}/security/blocklist'],
        ['authApps.security', 'addToBlocklist', 'POST', '/v1/auth-apps/{id}/security/blocklist'],
        ['authApps.security', 'removeFromBlocklist', 'DELETE', '/v1/auth-apps/{id}/security/blocklist/{entryId}'],
        ['authApps.plan', 'get', 'GET', '/v1/auth-apps/{id}/plan'],
        ['authApps.plan', 'change', 'PUT', '/v1/auth-apps/{id}/plan'],
        ['authApps.plan', 'cancelScheduledChange', 'DELETE', '/v1/auth-apps/{id}/plan/scheduled'],
        ['authApps.plan', 'costs', 'GET', '/v1/auth-apps/{id}/costs'],
        ['authApps.plan', 'usage', 'GET', '/v1/auth-apps/{id}/usage'],
        ['authApps.organizations', 'list', 'GET', '/v1/auth-apps/{id}/organizations'],
        ['authApps.organizations', 'create', 'POST', '/v1/auth-apps/{id}/organizations'],
        ['authApps.organizations', 'get', 'GET', '/v1/auth-apps/{id}/organizations/{orgId}'],
        ['authApps.organizations', 'update', 'PATCH', '/v1/auth-apps/{id}/organizations/{orgId}'],
        ['authApps.organizations', 'remove', 'DELETE', '/v1/auth-apps/{id}/organizations/{orgId}'],
        ['authApps.organizations', 'memberships', 'GET', '/v1/auth-apps/{id}/organizations/{orgId}/memberships'],
        ['authApps.organizations', 'addMembership', 'POST', '/v1/auth-apps/{id}/organizations/{orgId}/memberships'],
        ['authApps.organizations', 'membership', 'GET', '/v1/auth-apps/{id}/organizations/{orgId}/memberships/{userId}'],
        ['authApps.organizations', 'updateMembership', 'PATCH', '/v1/auth-apps/{id}/organizations/{orgId}/memberships/{userId}'],
        ['authApps.organizations', 'removeMembership', 'DELETE', '/v1/auth-apps/{id}/organizations/{orgId}/memberships/{userId}'],
        ['authApps.organizations', 'roles', 'GET', '/v1/auth-apps/{id}/organizations/{orgId}/roles'],
        ['authApps.organizations', 'putRole', 'PUT', '/v1/auth-apps/{id}/organizations/{orgId}/roles/{name}'],
        ['authApps.organizations', 'createRole', 'PUT', '/v1/auth-apps/{id}/organizations/{orgId}/roles/{name}'],
        ['authApps.organizations', 'updateRole', 'PUT', '/v1/auth-apps/{id}/organizations/{orgId}/roles/{name}'],
        ['authApps.organizations', 'removeRole', 'DELETE', '/v1/auth-apps/{id}/organizations/{orgId}/roles/{name}'],
        ['authApps.invitations', 'list', 'GET', '/v1/auth-apps/{id}/invitations'],
        ['authApps.invitations', 'create', 'POST', '/v1/auth-apps/{id}/invitations'],
        ['authApps.invitations', 'get', 'GET', '/v1/auth-apps/{id}/invitations/{invitationId}'],
        ['authApps.invitations', 'revoke', 'DELETE', '/v1/auth-apps/{id}/invitations/{invitationId}'],
        ['authApps.users', 'list', 'GET', '/v1/auth-apps/{id}/users'],
        ['authApps.users', 'get', 'GET', '/v1/auth-apps/{id}/users/{userId}'],
        ['authApps.users', 'update', 'PATCH', '/v1/auth-apps/{id}/users/{userId}'],
        ['authApps.users', 'block', 'POST', '/v1/auth-apps/{id}/users/{userId}/block'],
        ['authApps.users', 'unblock', 'POST', '/v1/auth-apps/{id}/users/{userId}/unblock'],
        ['authApps.users', 'remove', 'DELETE', '/v1/auth-apps/{id}/users/{userId}'],
        ['authApps.users', 'removeLikelyBots', 'POST', '/v1/auth-apps/{id}/users/bulk-delete'],
        ['authApps.users', 'sessions', 'GET', '/v1/auth-apps/{id}/users/{userId}/sessions'],
        ['authApps.users', 'signIns', 'GET', '/v1/auth-apps/{id}/users/{userId}/sign-ins'],
        ['authApps.users', 'passkeys', 'GET', '/v1/auth-apps/{id}/users/{userId}/passkeys'],
        ['authApps.users', 'removePasskey', 'DELETE', '/v1/auth-apps/{id}/users/{userId}/passkeys/{passkeyId}'],
        ['authApps.users', 'revokeSession', 'DELETE', '/v1/auth-apps/{id}/sessions/{sessionId}'],
        ['authApps.exports', 'create', 'POST', '/v1/auth-apps/{id}/exports'],
        ['authApps.exports', 'latest', 'GET', '/v1/auth-apps/{id}/exports/latest'],
        ['authApps.exports', 'get', 'GET', '/v1/auth-apps/{id}/exports/{exportId}'],
    ];

    /**
     * Routes the public OpenAPI document leaves out, so SpecContractTest can't
     * check them: the HonkIO Auth export download (GET /v1/auth-exports/{token}),
     * a single-use link rather than an API-key operation.
     * tests/AuthEndpointsTest.php checks it against the API source.
     *
     * @var list<array{string, string, string, string}>
     */
    public const UNCHECKED_AUTH = [
        ['authApps.exports', 'download', 'GET', '/v1/auth-exports/{token}'],
    ];

    /**
     * The push notification routes. The product is built but hidden from the
     * public OpenAPI document until launch (PUSH_ENABLED), so SpecContractTest
     * can't check them against the snapshot; tests/PushResourcesTest.php checks
     * them against the Node SDK's route table instead. At launch they move
     * into ALL.
     *
     * @var list<array{string, string, string, string}>
     */
    public const PUSH = [
        ['push', 'send', 'POST', '/v1/push'],
        ['push', 'get', 'GET', '/v1/push/{id}'],
        ['push', 'list', 'GET', '/v1/push'],
        ['push', 'update', 'PATCH', '/v1/push/{id}'],
        ['push', 'cancel', 'DELETE', '/v1/push/{id}'],
        ['push', 'usage', 'GET', '/v1/push/usage'],
        ['push.apps', 'create', 'POST', '/v1/push/apps'],
        ['push.apps', 'list', 'GET', '/v1/push/apps'],
        ['push.apps', 'get', 'GET', '/v1/push/apps/{id}'],
        ['push.apps', 'update', 'PATCH', '/v1/push/apps/{id}'],
        ['push.apps', 'delete', 'DELETE', '/v1/push/apps/{id}'],
        ['push.encryption', 'set', 'POST', '/v1/push/apps/{id}/encryption'],
        ['push.encryption', 'listKeys', 'GET', '/v1/push/apps/{id}/encryption/keys'],
        ['push.encryption', 'createKey', 'POST', '/v1/push/apps/{id}/encryption/keys'],
        ['push.encryption', 'activateKey', 'POST', '/v1/push/apps/{id}/encryption/keys/{kid}/activate'],
        ['push.devices', 'register', 'PUT', '/v1/push/devices'],
        ['push.devices', 'list', 'GET', '/v1/push/devices'],
        ['push.devices', 'delete', 'DELETE', '/v1/push/devices/{id}'],
    ];

    /**
     * The Notify routes (/v1/notify, /v1/notifications, /v1/recipients). Held back
     * from the public OpenAPI document until NOTIFY_ENABLED, like push, so
     * tests/NotifyResourcesTest.php checks them against the Node SDK's route
     * table. At launch they move into ALL.
     *
     * @var list<array{string, string, string, string}>
     */
    public const NOTIFY = [
        ['notify', 'send', 'POST', '/v1/notify'],
        ['notifications', 'get', 'GET', '/v1/notifications/{id}'],
        ['notifications', 'list', 'GET', '/v1/notifications'],
        ['recipients', 'upsert', 'PUT', '/v1/recipients/{user_id}'],
        ['recipients', 'update', 'PATCH', '/v1/recipients/{user_id}'],
        ['recipients', 'get', 'GET', '/v1/recipients/{user_id}'],
        ['recipients', 'list', 'GET', '/v1/recipients'],
        ['recipients', 'delete', 'DELETE', '/v1/recipients/{user_id}'],
    ];

    /**
     * Every route the SDK calls that the public snapshot does not have.
     *
     * @var list<array{string, string, string, string}>
     */
    public const UNCHECKED = [...self::UNCHECKED_AUTH, ...self::PUSH, ...self::NOTIFY];

    /**
     * Every HonkIO Auth route the SDK calls: its ALL entries and the export
     * download link. The Auth API reference is at https://honkio.ca/docs/auth/api.
     *
     * @var list<array{string, string, string, string}>
     */
    public const AUTH = [...self::AUTH_APPS, ...self::UNCHECKED_AUTH];

    /**
     * @deprecated 1.x: the old name of AUTH, from before HonkIO Auth launched.
     *
     * @var list<array{string, string, string, string}>
     */
    public const NOT_LAUNCHED = self::AUTH;
}
