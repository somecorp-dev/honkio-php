<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class ConsentBatches
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * A page of bulk consent uploads.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/compliance/consent-batches', [], $query);
    }

    /**
     * One bulk consent upload.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/compliance/consent-batches/{id}', ['id' => $id]);
    }
}
