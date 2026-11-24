<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class MonthlyBilling
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Monthly billing: billing_mode, status, credit limit, amount owed, saved card summary, next charge and the 6 most recent charges.
     * Read-only: HonkIO staff switch it on.
     *
     * @return array<mixed>
     */
    public function get(): array
    {
        return $this->http->callArray('GET', '/v1/monthly-billing', []);
    }
}
