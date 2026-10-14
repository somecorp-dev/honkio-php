<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class Emails
{
    public readonly ReceivedEmails $received;

    public function __construct(private readonly HttpClient $http)
    {
        $this->received = new ReceivedEmails($http);
    }

    /**
     * Send an email.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function send(array $params, ?string $idempotencyKey = null): array
    {
        return $this->http->callArray('POST', '/v1/emails', [], [], $params, $idempotencyKey, false, ['variables', 'headers']);
    }

    /**
     * One email.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/emails/{id}', ['id' => $id]);
    }

    /**
     * A page of emails.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/emails', [], $query);
    }

    /**
     * Reschedule a scheduled email: `['scheduled_at' => ...]`.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/emails/{id}', ['id' => $id], [], $params);
    }

    /**
     * Cancel a scheduled email. Returns null.
     */
    public function cancel(string $id): null
    {
        return $this->http->callNull('DELETE', '/v1/emails/{id}', ['id' => $id]);
    }
}
