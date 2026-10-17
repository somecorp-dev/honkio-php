<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\Dates;
use Honkio\Http\HttpClient;

final class Messages
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Send an SMS. The recipient needs consent on file.
     *
     * Returns the message plus `estimated_send_at`: an ISO 8601 estimate of
     * when the carrier will start sending it, from the parts already waiting
     * on the sending number; null in test mode and on a retry that returned
     * an earlier send.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function send(array $params, ?string $idempotencyKey = null): array
    {
        return $this->http->callArray('POST', '/v1/messages', [], [], $params, $idempotencyKey);
    }

    /**
     * One message by id.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/messages/{id}', ['id' => $id]);
    }

    /**
     * A page of messages, newest first. `date_from` and `date_to` are dates:
     * a string `YYYY-MM-DD`, or a DateTimeInterface (sent as its UTC date).
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/messages', [], Dates::dateOnly($query, ['date_from', 'date_to']));
    }
}
