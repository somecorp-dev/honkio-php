<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

/** The notification log. */
final class Notifications
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * One notification, with each channel tried and why.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/notifications/{id}', ['id' => $id]);
    }

    /**
     * A page of notifications, newest first: `user_id`, `status` (`pending`, `delivered` or `failed`), `limit`, `cursor`.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/notifications', [], $query);
    }
}
