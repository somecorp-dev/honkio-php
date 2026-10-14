<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class AutoTopup
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Auto top-up settings, saved card summary, pause state and recent attempts. Read-only: change them in the dashboard.
     *
     * @return array<mixed>
     */
    public function get(): array
    {
        return $this->http->callArray('GET', '/v1/auto-topup', []);
    }
}
