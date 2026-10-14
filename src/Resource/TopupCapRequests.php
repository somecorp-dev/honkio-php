<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class TopupCapRequests
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Ask for higher top-up limits.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/account/topup-cap-requests', [], [], $params);
    }

    /**
     * Your requests.
     *
     * @return array<mixed>
     */
    public function list(): array
    {
        return $this->http->callArray('GET', '/v1/account/topup-cap-requests', []);
    }
}
