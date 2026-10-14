<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class ReceivedEmails
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * A page of received emails.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/emails/received', [], $query);
    }

    /**
     * Your receiving address.
     *
     * @return array<mixed>
     */
    public function address(): array
    {
        return $this->http->callArray('GET', '/v1/emails/received/address', []);
    }

    /**
     * Turn the receiving address on or off.
     *
     * @return array<string, mixed>
     */
    public function setAddressEnabled(bool $enabled): array
    {
        return $this->http->callArray('PATCH', '/v1/emails/received/address', [], [], ['enabled' => $enabled]);
    }

    /**
     * One received email.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function get(string $id, array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/emails/received/{id}', ['id' => $id], $query);
    }

    /**
     * The raw MIME message, as a string of bytes.
     */
    public function raw(string $id): string
    {
        return $this->http->callRaw('GET', '/v1/emails/received/{id}/raw', ['id' => $id]);
    }

    /**
     * One attachment, as a string of bytes.
     */
    public function attachment(string $id, string $attachmentId): string
    {
        return $this->http->callRaw('GET', '/v1/emails/received/{id}/attachments/{attachmentId}', ['id' => $id, 'attachmentId' => $attachmentId]);
    }

    /**
     * Simulate an inbound email (test mode).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function simulate(array $params): array
    {
        return $this->http->callArray('POST', '/v1/emails/received/simulate', [], [], $params);
    }
}
