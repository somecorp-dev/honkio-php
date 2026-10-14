<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class Messages
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Send an SMS. The recipient needs consent on file.
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
     * A page of messages, newest first.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/messages', [], $query);
    }
}
