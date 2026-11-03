<?php

declare(strict_types=1);

namespace Honkio\Resource\Push;

use Honkio\Http\HttpClient;

/** Device tokens, registered per user. */
final class PushDevices
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Register (or refresh) a device token: `app_id`, `token`, `platform` ('ios'|'android'),
     * `user_id`, and `apns_environment` ('sandbox'|'production', required for iOS).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function register(array $params): array
    {
        return $this->http->callArray('PUT', '/v1/push/devices', [], [], $params);
    }

    /**
     * A page of devices: `app_id`, `user_id`, `active`, `limit`, `cursor`.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/push/devices', [], $query);
    }

    /**
     * Unregister a device. Returns null.
     */
    public function delete(string $id): null
    {
        return $this->http->callNull('DELETE', '/v1/push/devices/{id}', ['id' => $id]);
    }
}
