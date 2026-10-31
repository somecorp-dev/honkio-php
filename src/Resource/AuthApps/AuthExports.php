<?php

declare(strict_types=1);

namespace Honkio\Resource\AuthApps;

use Honkio\Http\HttpClient;

/**
 * `$honkio->authApps->exports`: the users, organizations, roles and passkey
 * public metadata of an app, generated in the background. Never tokens, codes
 * or keys. Needs the `auth_users` permission.
 */
final class AuthExports
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Start an export. It needs a step-up token for `auth_users:export`
     * (POST /v1/auth/step-up), sent as X-Step-Up-Token. The answer's
     * `download_url` is shown only here: keep it, poll get() until the status
     * is `ready`, then download() it once within 15 minutes. Lost it? latest()
     * answers a fresh one, with the same step-up.
     *
     * @return array<mixed>
     */
    public function create(string $appId, ?string $stepUpToken = null): array
    {
        $headers = $stepUpToken === null || $stepUpToken === '' ? [] : ['X-Step-Up-Token' => $stepUpToken];

        return $this->http->callArray('POST', '/v1/auth-apps/{id}/exports', ['id' => $appId], [], [], null, false, [], $headers);
    }

    /**
     * One export, its status and counts. The file is downloaded from the link create() returned.
     *
     * @return array<mixed>
     */
    public function get(string $appId, string $exportId): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/exports/{exportId}', ['id' => $appId, 'exportId' => $exportId]);
    }

    /**
     * The app's newest export, whatever its status (a NotFoundException when
     * it has none). While it is `ready`, the key that requested it, passing an
     * `auth_users:export` step-up token (the same step-up as create()), also
     * gets a fresh single-use `download_url`: it replaces the earlier link,
     * which stops working, and expires with the export. Without the step-up,
     * from any other key, or in any other status, `download_url` is null and
     * the earlier link keeps working.
     *
     * @return array<mixed>
     */
    public function latest(string $appId, ?string $stepUpToken = null): array
    {
        $headers = $stepUpToken === null || $stepUpToken === '' ? [] : ['X-Step-Up-Token' => $stepUpToken];

        return $this->http->callArray('GET', '/v1/auth-apps/{id}/exports/latest', ['id' => $appId], [], null, null, false, [], $headers);
    }

    /**
     * Download a ready export, as a string, by its link's token or the whole
     * download_url. The link works once.
     */
    public function download(string $tokenOrUrl): string
    {
        $token = $tokenOrUrl;
        if (str_contains($tokenOrUrl, '/')) {
            $segments = array_values(array_filter(explode('/', explode('?', $tokenOrUrl, 2)[0]), static fn (string $s): bool => $s !== ''));
            $token = $segments === [] ? '' : $segments[count($segments) - 1];
        }

        return $this->http->callRaw('GET', '/v1/auth-exports/{token}', ['token' => $token]);
    }
}
