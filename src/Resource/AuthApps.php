<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;
use Honkio\Resource\AuthApps\AuthExports;
use Honkio\Resource\AuthApps\AuthInvitations;
use Honkio\Resource\AuthApps\AuthOrganizations;
use Honkio\Resource\AuthApps\AuthPlan;
use Honkio\Resource\AuthApps\AuthSecurity;
use Honkio\Resource\AuthApps\AuthUsers;

/**
 * Honkio Auth apps: hosted, passwordless OIDC sign-in on a hostname you own.
 * Not available yet: the routes answer 404 until your account is enabled.
 *
 * Sign-in itself is standard OpenID Connect against the app's issuer; to check
 * the tokens it issues, see {@see \Honkio\Auth\HonkioAuth}.
 */
final class AuthApps
{
    /** Throwaway-email, alias, VoIP and new-device settings, attack mode, the blocklist and the Security tab's counts. */
    public readonly AuthSecurity $security;
    /** The app's plan, a scheduled change, costs by sign-in method and usage. */
    public readonly AuthPlan $plan;
    /** Organizations, their roles and memberships. */
    public readonly AuthOrganizations $organizations;
    /** Invitations to the app or to one of its organizations. */
    public readonly AuthInvitations $invitations;
    /** The app's users and their sessions. */
    public readonly AuthUsers $users;
    /** Exports of the app's users and organizations. */
    public readonly AuthExports $exports;

    public function __construct(private readonly HttpClient $http)
    {
        $this->security = new AuthSecurity($http);
        $this->plan = new AuthPlan($http);
        $this->organizations = new AuthOrganizations($http);
        $this->invitations = new AuthInvitations($http);
        $this->users = new AuthUsers($http);
        $this->exports = new AuthExports($http);
    }

    /**
     * Create an app on a hostname you own (`hostname`, `name`, `redirect_uris`,
     * optional `post_logout_redirect_uris` and `confidential`). The response
     * carries the DNS records to publish and, for a confidential app, the
     * client secret, shown once.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/auth-apps', [], [], $params);
    }

    /**
     * Your apps in the key's mode, newest first (`limit`, `cursor`).
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps', [], $query);
    }

    /**
     * One app, with its issuer, client id, DNS records and domain status.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}', ['id' => $id]);
    }

    /**
     * Change an app: name, brand colour, redirect URIs, sign-in methods,
     * `passkey_scope`, `sms_from_number_id`, `send_from_email_domain_id`.
     *
     * A `hostname` is sent on its own: it moves the app to a new hostname you
     * own, keeping the same users, client id and secret. The app keeps serving
     * on its current hostname while the new one waits in `pending_hostname`
     * until its DNS records check out ({@see verifyDomain()}) and its
     * certificate is active; then the issuer changes and every session ends.
     * The answer's `hostname_change` says what the switch does to passkeys.
     * Cancel it with {@see cancelHostnameChange()}.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/auth-apps/{id}', ['id' => $id], [], $params);
    }

    /**
     * Drop the hostname the app was moving to (and its certificate). The app
     * keeps its current hostname, issuer, sessions and passkeys. Returns the
     * app. A NotFoundException when no change is waiting, including one that
     * has already switched.
     *
     * @return array<mixed>
     */
    public function cancelHostnameChange(string $id): array
    {
        return $this->http->callArray('DELETE', '/v1/auth-apps/{id}/hostname-change', ['id' => $id]);
    }

    /**
     * Delete an app and free its hostname. Its users can no longer sign in. Returns null.
     */
    public function remove(string $id): null
    {
        return $this->http->callNull('DELETE', '/v1/auth-apps/{id}', ['id' => $id]);
    }

    /**
     * Replace the client secret. The response carries the new one once; the old one stops working.
     *
     * @return array<mixed>
     */
    public function rotateSecret(string $id): array
    {
        return $this->http->callArray('POST', '/v1/auth-apps/{id}/rotate-secret', ['id' => $id]);
    }

    /**
     * Check the app's TXT and CNAME records and its certificate now.
     *
     * @return array<mixed>
     */
    public function verifyDomain(string $id): array
    {
        return $this->http->callArray('POST', '/v1/auth-apps/{id}/domain/verify', ['id' => $id]);
    }

    /**
     * Upload the logo shown on the sign-in page: the image's bytes and its
     * type (image/png, image/jpeg, image/webp or image/gif), at most 1 MB.
     * It is sent as multipart/form-data in a part named `logo`. Honkio
     * re-encodes it; SVG is not accepted. Returns the app.
     *
     * @return array<mixed>
     */
    public function uploadLogo(string $id, string $image, string $contentType, ?string $filename = null): array
    {
        $filename ??= 'logo.' . (explode('/', $contentType)[1] ?? 'bin');

        return $this->http->callMultipartFile('PUT', '/v1/auth-apps/{id}/logo', ['id' => $id], 'logo', $image, $contentType, $filename);
    }

    /**
     * Remove the logo: the sign-in page goes back to the brand-colour mark. Returns the app.
     *
     * @return array<mixed>
     */
    public function removeLogo(string $id): array
    {
        return $this->http->callArray('DELETE', '/v1/auth-apps/{id}/logo', ['id' => $id]);
    }

    /**
     * Sign-in history across the app (`user_id`, `limit`, `cursor`).
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function signIns(string $id, array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/sign-ins', ['id' => $id], $query);
    }

    /**
     * Who may sign up (`sign_up_mode`, `sign_up_domains`) and whether two-step is required.
     *
     * @return array<mixed>
     */
    public function signInPolicy(string $id): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/sign-in-policy', ['id' => $id]);
    }

    /**
     * Change the sign-in policy.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function updateSignInPolicy(string $id, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/auth-apps/{id}/sign-in-policy', ['id' => $id], [], $params);
    }
}
