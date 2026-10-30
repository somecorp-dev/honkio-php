<?php

declare(strict_types=1);

namespace Honkio\Resource\AuthApps;

use Honkio\Http\HttpClient;

/** `$honkio->authApps->invitations`: single-use, 7-day invitations. Needs the `auth_organizations` permission. */
final class AuthInvitations
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * The app's open invitations (`organization_id`, `limit`, `cursor`).
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(string $appId, array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/invitations', ['id' => $appId], $query);
    }

    /**
     * Invite an email address or mobile number (`email` or `phone`, optional
     * `organization_id`, `role`, `redirect_url`, `locale` and `send_email`).
     * It is accepted by signing in as that identifier. The answer's `url` is
     * the invitation link; with `send_email` false, share it yourself.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(string $appId, array $params): array
    {
        return $this->http->callArray('POST', '/v1/auth-apps/{id}/invitations', ['id' => $appId], [], $params);
    }

    /**
     * One invitation and its status (`pending`, `accepted`, `revoked` or `expired`).
     *
     * @return array<mixed>
     */
    public function get(string $appId, string $invitationId): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/invitations/{invitationId}', ['id' => $appId, 'invitationId' => $invitationId]);
    }

    /**
     * Revoke an invitation: the link stops working. Returns the revoked invitation.
     *
     * @return array<mixed>
     */
    public function revoke(string $appId, string $invitationId): array
    {
        return $this->http->callArray('DELETE', '/v1/auth-apps/{id}/invitations/{invitationId}', ['id' => $appId, 'invitationId' => $invitationId]);
    }
}
