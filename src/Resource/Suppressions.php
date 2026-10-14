<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class Suppressions
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Suppress an address: `['email_address' => ...]`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/email-suppressions', [], [], [
            'email_address' => $params['email_address'] ?? null,
            'reason' => 'manual',
        ]);
    }

    /**
     * A page of suppressed addresses.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/email-suppressions', [], $query);
    }

    /**
     * Lift a suppression. Returns null.
     */
    public function remove(string $emailAddress): null
    {
        return $this->http->callNull('DELETE', '/v1/email-suppressions/{address}', ['address' => $emailAddress]);
    }
}
