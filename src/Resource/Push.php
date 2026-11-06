<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;
use Honkio\Resource\Push\PushApps;
use Honkio\Resource\Push\PushDevices;
use Honkio\Resource\Push\PushEncryption;

final class Push
{
    /** Push apps: the APNs and FCM credentials a push is sent with. */
    public readonly PushApps $apps;
    /** Payload encryption for an app. Live keys only. */
    public readonly PushEncryption $encryption;
    /** Device tokens, registered per user. */
    public readonly PushDevices $devices;

    public function __construct(private readonly HttpClient $http)
    {
        $this->apps = new PushApps($http);
        $this->encryption = new PushEncryption($http);
        $this->devices = new PushDevices($http);
    }

    /**
     * Send a push to every active device of `to['user_id']`, or to one `to['device_id']`.
     * `data` is a map of string values; its keys are sent as given. `fallback` is
     * `['sms' => ['to' => ..., 'from' => ..., 'body' => ...]]`, sent as a text
     * when no device accepts the push (the key needs `messages:w`).
     * `collapse_key` (1 to 64 visible ASCII characters) lets a newer push with the same key
     * replace an older one not yet shown.
     * `scheduled_at` (1 minute to 30 days ahead) sends it later: the push comes back with
     * status `scheduled` and is charged now. Move it with {@see update()} or cancel it
     * (refunded) with {@see cancel()}.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function send(array $params, ?string $idempotencyKey = null): array
    {
        return $this->http->callArray('POST', '/v1/push', [], [], $params, $idempotencyKey, false, ['data']);
    }

    /**
     * One push, with each device's delivery result.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/push/{id}', ['id' => $id]);
    }

    /**
     * A page of pushes: `app_id`, `user_id`, `status`, `created_after`, `created_before`, `limit`, `cursor`.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/push', [], $query);
    }

    /**
     * Reschedule a scheduled push: `['scheduled_at' => ...]`.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/push/{id}', ['id' => $id], [], $params);
    }

    /**
     * Cancel a scheduled push. What it reserved is refunded. Returns null.
     */
    public function cancel(string $id): null
    {
        return $this->http->callNull('DELETE', '/v1/push/{id}', ['id' => $id]);
    }

    /**
     * The free allotment used this calendar month (Toronto time).
     *
     * @return array<mixed>
     */
    public function usage(): array
    {
        return $this->http->callArray('GET', '/v1/push/usage');
    }
}
