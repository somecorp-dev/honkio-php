<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class Pricing
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Current prices in CAD: message_cost_cents per SMS segment,
     * verification_cost_cents for a typical verification, local and toll-free
     * number rent and fees, inbound SMS, and the email and push prices
     * (millicents, where the field name says so) while those products are
     * available. The same figures as the pricing page.
     *
     * @return array<mixed>
     */
    public function get(): array
    {
        return $this->http->callArray('GET', '/v1/pricing', []);
    }
}
