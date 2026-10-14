<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class PhoneNumbers
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Area codes with numbers available.
     *
     * @return array<mixed>
     */
    public function areaCodes(): array
    {
        return $this->http->callArray('GET', '/v1/phone-numbers/area-codes', []);
    }

    /**
     * Search available numbers. `area_codes` may be a list; the API takes one
     * comma-separated value, so it is joined here.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function search(array $query = []): array
    {
        if (isset($query['area_codes']) && is_array($query['area_codes'])) {
            $query['area_codes'] = $query['area_codes'] === [] ? null : implode(',', $query['area_codes']);
        }

        return $this->http->callArray('GET', '/v1/phone-numbers/search', [], $query);
    }

    /**
     * Provision a number.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function provision(array $params): array
    {
        return $this->http->callArray('POST', '/v1/phone-numbers', [], [], $params);
    }

    /**
     * Your numbers.
     *
     * @return array<mixed>
     */
    public function list(): array
    {
        return $this->http->callArray('GET', '/v1/phone-numbers', []);
    }

    /**
     * One number.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/phone-numbers/{id}', ['id' => $id]);
    }

    /**
     * Release a number. Returns null.
     */
    public function release(string $id): null
    {
        return $this->http->callNull('DELETE', '/v1/phone-numbers/{id}', ['id' => $id]);
    }
}
