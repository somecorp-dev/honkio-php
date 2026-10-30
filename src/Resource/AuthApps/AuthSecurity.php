<?php

declare(strict_types=1);

namespace Honkio\Resource\AuthApps;

use Honkio\Http\HttpClient;

/** `$honkio->authApps->security`: an app's abuse settings and blocklist. */
final class AuthSecurity
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * The app's settings: `block_throwaway_emails`, `aliases_are_same`,
     * `block_voip`, `new_device_alerts`, `sms_daily_limit_cents`, `attack_mode`.
     *
     * @return array<mixed>
     */
    public function get(string $appId): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/security', ['id' => $appId]);
    }

    /**
     * Change the settings; `attack_mode` takes `on`, `off` or `auto`.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $appId, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/auth-apps/{id}/security', ['id' => $appId], [], $params);
    }

    /**
     * The Security tab's counts: blocked sign-ups, challenges, attack mode.
     *
     * @return array<mixed>
     */
    public function summary(string $appId): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/security/summary', ['id' => $appId]);
    }

    /**
     * The app's blocklist entries.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function blocklist(string $appId, array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/security/blocklist', ['id' => $appId], $query);
    }

    /**
     * Block an email, email domain, phone, phone prefix, IP, IP range, network
     * provider or country (`kind`, `value`, optional `note`).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function addToBlocklist(string $appId, array $params): array
    {
        return $this->http->callArray('POST', '/v1/auth-apps/{id}/security/blocklist', ['id' => $appId], [], $params);
    }

    /**
     * Remove a blocklist entry. Returns null.
     */
    public function removeFromBlocklist(string $appId, string $entryId): null
    {
        return $this->http->callNull('DELETE', '/v1/auth-apps/{id}/security/blocklist/{entryId}', ['id' => $appId, 'entryId' => $entryId]);
    }
}
