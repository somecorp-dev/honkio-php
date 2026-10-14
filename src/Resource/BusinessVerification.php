<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class BusinessVerification
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Your business profile, or null if you have not saved one.
     *
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        return $this->http->callNullableArray('GET', '/v1/business-verification', []);
    }

    /**
     * Save the business profile.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function save(array $params): array
    {
        return $this->http->callArray('PUT', '/v1/business-verification', [], [], $params);
    }

    /**
     * Submit the profile for review.
     *
     * @return array<mixed>
     */
    public function submit(): array
    {
        return $this->http->callArray('POST', '/v1/business-verification/submit', []);
    }
}
