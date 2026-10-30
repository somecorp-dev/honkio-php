<?php

declare(strict_types=1);

namespace Honkio\Resource\AuthApps;

use Honkio\Http\HttpClient;

/** `$honkio->authApps->organizations`: organizations, roles and memberships. Needs the `auth_organizations` permission. */
final class AuthOrganizations
{
    private const ORG = '/v1/auth-apps/{id}/organizations/{orgId}';

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * The app's organizations (`limit`, `cursor`).
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(string $appId, array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/organizations', ['id' => $appId], $query);
    }

    /**
     * Create an organization (`name`, optional `slug` and `two_step_required`).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(string $appId, array $params): array
    {
        return $this->http->callArray('POST', '/v1/auth-apps/{id}/organizations', ['id' => $appId], [], $params);
    }

    /**
     * One organization.
     *
     * @return array<mixed>
     */
    public function get(string $appId, string $orgId): array
    {
        return $this->http->callArray('GET', self::ORG, ['id' => $appId, 'orgId' => $orgId]);
    }

    /**
     * Change an organization.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $appId, string $orgId, array $params): array
    {
        return $this->http->callArray('PATCH', self::ORG, ['id' => $appId, 'orgId' => $orgId], [], $params);
    }

    /**
     * Delete an organization and its memberships. Returns null.
     */
    public function remove(string $appId, string $orgId): null
    {
        return $this->http->callNull('DELETE', self::ORG, ['id' => $appId, 'orgId' => $orgId]);
    }

    /**
     * An organization's members and their roles.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function memberships(string $appId, string $orgId, array $query = []): array
    {
        return $this->http->callArray('GET', self::ORG . '/memberships', ['id' => $appId, 'orgId' => $orgId], $query);
    }

    /**
     * Add a user to an organization (`user_id`, `role`).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function addMembership(string $appId, string $orgId, array $params): array
    {
        return $this->http->callArray('POST', self::ORG . '/memberships', ['id' => $appId, 'orgId' => $orgId], [], $params);
    }

    /**
     * Change a member's role (`role`).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function updateMembership(string $appId, string $orgId, string $userId, array $params): array
    {
        return $this->http->callArray('PATCH', self::ORG . '/memberships/{userId}', ['id' => $appId, 'orgId' => $orgId, 'userId' => $userId], [], $params);
    }

    /**
     * Remove a user from an organization. Their next token refresh is refused. Returns null.
     */
    public function removeMembership(string $appId, string $orgId, string $userId): null
    {
        return $this->http->callNull('DELETE', self::ORG . '/memberships/{userId}', ['id' => $appId, 'orgId' => $orgId, 'userId' => $userId]);
    }

    /**
     * An organization's roles (`admin`, `member` and your own).
     *
     * @return array<mixed>
     */
    public function roles(string $appId, string $orgId): array
    {
        return $this->http->callArray('GET', self::ORG . '/roles', ['id' => $appId, 'orgId' => $orgId]);
    }

    /**
     * Add a role (`name`, optional `two_step_required`).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function createRole(string $appId, string $orgId, array $params): array
    {
        return $this->http->callArray('POST', self::ORG . '/roles', ['id' => $appId, 'orgId' => $orgId], [], $params);
    }

    /**
     * Change a role (`two_step_required`).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function updateRole(string $appId, string $orgId, string $name, array $params): array
    {
        return $this->http->callArray('PATCH', self::ORG . '/roles/{name}', ['id' => $appId, 'orgId' => $orgId, 'name' => $name], [], $params);
    }

    /**
     * Delete a role. Returns null.
     */
    public function removeRole(string $appId, string $orgId, string $name): null
    {
        return $this->http->callNull('DELETE', self::ORG . '/roles/{name}', ['id' => $appId, 'orgId' => $orgId, 'name' => $name]);
    }
}
