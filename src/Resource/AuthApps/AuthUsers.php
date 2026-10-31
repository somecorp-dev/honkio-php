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
     * Change a user (`name`, `metadata`, `email`, `email_verified`, `phone`,
     * `phone_verified`, `locale`). Changing an email or phone notifies the old
     * one, in `locale`.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $appId, string $userId, array $params): array
    {
        return $this->http->callArray('PATCH', self::USER, ['id' => $appId, 'userId' => $userId], [], $params, null, false, ['metadata']);
    }

    /**
     * Block a user, or unblock them (`$blocked = false`). Blocking ends every
     * session at once and sends user.blocked. Returns the user.
     *
     * @return array<mixed>
     */
    public function block(string $appId, string $userId, bool $blocked = true, ?string $reason = null): array
    {
        if (!$blocked) {
            return $this->unblock($appId, $userId);
        }
        $params = $reason === null ? [] : ['reason' => $reason];

        return $this->http->callArray('POST', self::USER . '/block', ['id' => $appId, 'userId' => $userId], [], $params);
    }

    /**
     * Unblock a user. Returns the user.
     *
     * @return array<mixed>
     */
    public function unblock(string $appId, string $userId): array
    {
        return $this->http->callArray('POST', self::USER . '/unblock', ['id' => $appId, 'userId' => $userId]);
    }

    /**
     * Delete a user, their sessions and passkeys. Returns null.
     */
    public function remove(string $appId, string $userId): null
    {
        return $this->http->callNull('DELETE', self::USER, ['id' => $appId, 'userId' => $userId]);
    }

    /**
     * Delete users flagged as likely bots, a batch at a time: call it again
     * while the answer's `more` is true. `$createdBefore` (ISO 8601) limits it
     * to older ones. Returns `deleted` and `more`. It needs a step-up token
     * for `auth_users:bulk_delete` (POST /v1/auth/step-up), sent as
     * X-Step-Up-Token, one per call.
     *
     * @return array<mixed>
     */
    public function removeLikelyBots(string $appId, ?string $createdBefore = null, ?string $stepUpToken = null): array
    {
        $params = ['likely_bot' => true];
        if ($createdBefore !== null) {
            $params['created_before'] = $createdBefore;
        }
        $headers = $stepUpToken === null || $stepUpToken === '' ? [] : ['X-Step-Up-Token' => $stepUpToken];

        return $this->http->callArray('POST', '/v1/auth-apps/{id}/users/bulk-delete', ['id' => $appId], [], $params, null, false, [], $headers);
    }

    /**
     * A user's sessions, newest first (`active`, `limit`, `cursor`).
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function sessions(string $appId, string $userId, array $query = []): array
    {
        return $this->http->callArray('GET', self::USER . '/sessions', ['id' => $appId, 'userId' => $userId], $query);
    }

    /**
     * A user's sign-in history, newest first (`limit`, `cursor`).
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function signIns(string $appId, string $userId, array $query = []): array
    {
        return $this->http->callArray('GET', self::USER . '/sign-ins', ['id' => $appId, 'userId' => $userId], $query);
    }

    /**
     * A user's passkeys: public metadata only, in `data`. A passkey's `usable`
     * is false when it was made under a passkey scope the app no longer uses.
     *
     * @return array<mixed>
     */
    public function passkeys(string $appId, string $userId): array
    {
        return $this->http->callArray('GET', self::USER . '/passkeys', ['id' => $appId, 'userId' => $userId]);
    }

    /**
     * Remove one of a user's passkeys. The user is told at their email
     * address, their sessions end, and user.updated is sent with changes
     * `["passkeys"]`. Needs `auth_users` with delete. Returns null.
     */
    public function removePasskey(string $appId, string $userId, string $passkeyId): null
    {
        return $this->http->callNull('DELETE', self::USER . '/passkeys/{passkeyId}', ['id' => $appId, 'userId' => $userId, 'passkeyId' => $passkeyId]);
    }

    /**
     * End one session. Its refresh token stops working at once. Returns null.
     */
    public function revokeSession(string $appId, string $sessionId): null
    {
        return $this->http->callNull('DELETE', '/v1/auth-apps/{id}/sessions/{sessionId}', ['id' => $appId, 'sessionId' => $sessionId]);
    }
}
