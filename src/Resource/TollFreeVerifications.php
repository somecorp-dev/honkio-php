<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class TollFreeVerifications
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Allowed values for the submission form.
     *
     * @return array<mixed>
     */
    public function options(): array
    {
        return $this->http->callArray('GET', '/v1/toll-free-verifications/options', []);
    }

    /**
     * Start a toll-free verification.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/toll-free-verifications', [], [], $params);
    }

    /**
     * Change a draft.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/toll-free-verifications/{id}', ['id' => $id], [], $params);
    }

    /**
     * Submit for review.
     *
     * @return array<mixed>
     */
    public function submit(string $id): array
    {
        return $this->http->callArray('POST', '/v1/toll-free-verifications/{id}/submit', ['id' => $id]);
    }

    /**
     * Cancel a verification. Returns null.
     */
    public function cancel(string $id): null
    {
        return $this->http->callNull('DELETE', '/v1/toll-free-verifications/{id}', ['id' => $id]);
    }

    /**
     * One verification.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/toll-free-verifications/{id}', ['id' => $id]);
    }

    /**
     * Your verifications.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/toll-free-verifications', [], $query);
    }
}
