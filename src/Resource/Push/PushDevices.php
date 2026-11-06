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
     * `user_id`, `apns_environment` ('sandbox'|'production', required for iOS), and
     * `encryption_public_key`: the key the HonkIO Swift or Android push package generated on the
     * device (base64url, no padding, of the 65-byte uncompressed P-256 point). Pushes to the device
     * are then encrypted to it. A new key replaces the stored one, leaving it out keeps it, and null
     * removes it. The device comes back with `encryption_key_id`, never the key.
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
