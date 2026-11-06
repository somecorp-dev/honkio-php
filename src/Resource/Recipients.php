<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

/** Recipient profiles: contact details for your users, keyed by your own user id. */
final class Recipients
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Create the profile, or replace every field of an existing one: `phone_number`, `email`
     * and `language` (`en` or `fr`). A field left out is cleared; null clears it too.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function upsert(string $userId, array $params = []): array
    {
        return $this->http->callArray('PUT', '/v1/recipients/{user_id}', ['user_id' => $userId], [], $params);
    }

    /**
     * Change only the fields given; null clears one. Throws when there is no profile.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $userId, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/recipients/{user_id}', ['user_id' => $userId], [], $params);
    }

    /**
     * One profile, with its active push device count.
     *
     * @return array<mixed>
     */
    public function get(string $userId): array
    {
        return $this->http->callArray('GET', '/v1/recipients/{user_id}', ['user_id' => $userId]);
    }

    /**
     * A page of profiles: `limit`, `cursor`.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/recipients', [], $query);
    }

    /**
     * Delete the profile and unregister that user's push devices (which needs push delete
     * permission too). Returns null.
     */
    public function delete(string $userId): null
    {
        return $this->http->callNull('DELETE', '/v1/recipients/{user_id}', ['user_id' => $userId]);
    }
}
