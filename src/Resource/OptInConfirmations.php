<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class OptInConfirmations
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Send a double opt-in confirmation message.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function send(array $params): array
    {
        return $this->http->callArray('POST', '/v1/compliance/opt-in-confirmations', [], [], $params);
    }

    /**
     * Confirmations sent.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/compliance/opt-in-confirmations', [], $query);
    }
}
