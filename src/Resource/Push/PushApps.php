<?php

declare(strict_types=1);

namespace Honkio\Resource\Push;

use Honkio\Http\HttpClient;

/** Push apps. APNs and FCM credentials are write-only: the API never returns them. */
final class PushApps
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Create an app. `apns` is `['key_p8', 'key_id', 'team_id', 'bundle_id']`; `fcm` is `['service_account_json']`.
     * `require_encryption` true refuses any delivery that cannot be encrypted: a device registered
     * without `encryption_public_key` is skipped with `ENCRYPTION_KEY_MISSING`, never sent in
     * plaintext and never charged. False (the default) encrypts for each device that has a key and
     * sends plaintext to the rest.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/push/apps', [], [], $params);
    }

    /**
     * A page of apps: `limit`, `cursor`.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/push/apps', [], $query);
    }

    /**
     * One app.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/push/apps/{id}', ['id' => $id]);
    }

    /**
     * Change an app. Send `apns` or `fcm` to replace that provider's credentials, and
     * `require_encryption` to change whether a device without a key is refused (see {@see create()}).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/push/apps/{id}', ['id' => $id], [], $params);
    }

    /**
     * Delete an app. Returns null.
     */
    public function delete(string $id): null
    {
        return $this->http->callNull('DELETE', '/v1/push/apps/{id}', ['id' => $id]);
    }
}
