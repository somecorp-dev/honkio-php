<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class Verify
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Send a verification code.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function start(array $params): array
    {
        return $this->http->callArray('POST', '/v1/verify', [], [], $params);
    }

    /**
     * Check a code.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function check(string $id, array $params): array
    {
        return $this->http->callArray('POST', '/v1/verify/{id}/check', ['id' => $id], [], $params);
    }

    /**
     * One verification.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/verify/{id}', ['id' => $id]);
    }

    /**
     * A page of verifications.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/verify', [], $query);
    }
}
