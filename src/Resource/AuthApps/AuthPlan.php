<?php

declare(strict_types=1);

namespace Honkio\Resource\AuthApps;

use Honkio\Http\HttpClient;

/** `$honkio->authApps->plan`: an app's plan, costs and usage. */
final class AuthPlan
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * The current plan, any scheduled change and the billing state.
     *
     * @return array<mixed>
     */
    public function get(string $appId): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/plan', ['id' => $appId]);
    }

    /**
     * Change the plan (`free`, `pro` or `business`). An upgrade applies now; a
     * downgrade is scheduled for the end of the billing cycle, and the response
     * lists what it turns off. `$dryRun` previews the change and its cost
     * without making it.
     *
     * @return array<mixed>
     */
    public function change(string $appId, string $plan, bool $dryRun = false): array
    {
        $params = $dryRun ? ['plan' => $plan, 'dry_run' => true] : ['plan' => $plan];

        return $this->http->callArray('PUT', '/v1/auth-apps/{id}/plan', ['id' => $appId], [], $params);
    }

    /**
     * Cancel a scheduled downgrade. Returns null.
     */
    public function cancelScheduledChange(string $appId): null
    {
        return $this->http->callNull('DELETE', '/v1/auth-apps/{id}/plan/scheduled', ['id' => $appId]);
    }

    /**
     * Sign-in costs by method (email, text, passkey) for a month (`YYYY-MM`; the current month when null).
     *
     * @return array<mixed>
     */
    public function costs(string $appId, ?string $month = null): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/costs', ['id' => $appId], ['month' => $month]);
    }

    /**
     * Returning users this month against the plan's included users.
     *
     * @return array<mixed>
     */
    public function usage(string $appId): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/usage', ['id' => $appId]);
    }
}
