<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class SendLimit
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Your sending limits and current usage: the daily cap, the per-recipient
     * caps, probation, any pause, past volume requests, and number_queues
     * (each number's carrier queue: phone_number, parts_per_minute,
     * queued_parts, and clears_at, when the waiting parts should have gone
     * out, or null when none are waiting).
     *
     * @return array<mixed>
     */
    public function get(): array
    {
        return $this->http->callArray('GET', '/v1/send-limit', []);
    }
}
