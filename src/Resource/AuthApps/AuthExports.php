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
     * Start an export. Poll get() until its status is `ready`.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(string $appId, array $params = []): array
    {
        return $this->http->callArray('POST', '/v1/auth-apps/{id}/exports', ['id' => $appId], [], $params);
    }

    /**
     * One export and its status; a ready one carries a single-use download link that works for 15 minutes.
     *
     * @return array<mixed>
     */
    public function get(string $appId, string $exportId): array
    {
        return $this->http->callArray('GET', '/v1/auth-apps/{id}/exports/{exportId}', ['id' => $appId, 'exportId' => $exportId]);
    }

    /**
     * Download a ready export by its link's token, as a string. The link works once.
     */
    public function download(string $token): string
    {
        return $this->http->callRaw('GET', '/v1/auth-exports/{token}', ['token' => $token]);
    }
}
