<?php

declare(strict_types=1);

namespace Honkio\Resource\AuthApps;

use Honkio\Http\HttpClient;

/** `$honkio->authApps->users`: an app's users and their sessions. Needs the `auth_users` permission. */
final class AuthUsers
{
    private const USER = '/v1/auth-apps/{id}/users/{userId}';

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * A page of users (`limit`, `cursor`, and the filters the API offers).
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(string $appId, array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/users', ['id' => $appId], $query);
    }

    /**
     * One user.
     *
     * @return array<mixed>
     */
    public function get(string $appId, string $userId): array
    {
        return $this->http->callArray('GET', self::USER, ['id' => $appId, 'userId' => $userId]);
    }

    /**
     * Change a user (`name`, `metadata`, `email`, `phone`). Changing an email
     * or phone notifies the old one.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $appId, string $userId, array $params): array
    {
        return $this->http->callArray('PATCH', self::USER, ['id' => $appId, 'userId' => $userId], [], $params, null, false, ['metadata']);
    }

    /**
     * Block a user (true) or unblock them (false). Blocking ends every session at once.
     *
     * @return array<mixed>
     */
    public function block(string $appId, string $userId, bool $blocked = true, ?string $reason = null): array
    {
        $params = ['blocked' => $blocked];
        if ($reason !== null) {
            $params['blocked_reason'] = $reason;
        }

        return $this->http->callArray('PATCH', self::USER, ['id' => $appId, 'userId' => $userId], [], $params);
    }

    /**
     * Delete a user, their sessions and passkeys. Returns null.
     */
    public function remove(string $appId, string $userId): null
    {
        return $this->http->callNull('DELETE', self::USER, ['id' => $appId, 'userId' => $userId]);
    }

    /**
     * Delete every user flagged as a likely bot. Returns the API's summary (how many were deleted).
     *
     * @return array<mixed>|null
     */
    public function removeLikelyBots(string $appId): ?array
    {
        return $this->http->callWithQuery('DELETE', '/v1/auth-apps/{id}/users', ['id' => $appId], ['likely_bot' => true]);
    }

    /**
     * A user's active sessions.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function sessions(string $appId, string $userId, array $query = []): array
    {
        return $this->http->callArray('GET', self::USER . '/sessions', ['id' => $appId, 'userId' => $userId], $query);
    }

    /**
     * End one session. Its refresh token stops working at once. Returns null.
     */
    public function revokeSession(string $appId, string $sessionId): null
    {
        return $this->http->callNull('DELETE', '/v1/auth-apps/{id}/sessions/{sessionId}', ['id' => $appId, 'sessionId' => $sessionId]);
    }
}
