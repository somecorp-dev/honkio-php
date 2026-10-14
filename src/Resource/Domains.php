<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class Domains
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Add a sending domain.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/email-domains', [], [], $params);
    }

    /**
     * One domain with its DNS records.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/email-domains/{id}', ['id' => $id]);
    }

    /**
     * Your domains.
     *
     * @return array<mixed>
     */
    public function list(): array
    {
        return $this->http->callArray('GET', '/v1/email-domains', []);
    }

    /**
     * Change a domain's settings.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/email-domains/{id}', ['id' => $id], [], $params);
    }

    /**
     * Check the domain's DNS records now.
     *
     * @return array<mixed>
     */
    public function verify(string $id): array
    {
        return $this->http->callArray('POST', '/v1/email-domains/{id}/verify', ['id' => $id]);
    }

    /**
     * Remove a domain. Returns null.
     */
    public function remove(string $id): null
    {
        return $this->http->callNull('DELETE', '/v1/email-domains/{id}', ['id' => $id]);
    }
}
