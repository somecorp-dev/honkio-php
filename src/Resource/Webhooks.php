<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\SignatureVerificationException;
use Honkio\Http\HttpClient;
use Honkio\Webhook;

final class Webhooks
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Create a webhook endpoint. The response carries the signing secret once.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/webhooks', [], [], $params);
    }

    /**
     * Your webhooks.
     *
     * @return array<mixed>
     */
    public function list(): array
    {
        return $this->http->callArray('GET', '/v1/webhooks', []);
    }

    /**
     * One webhook.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->http->callArray('GET', '/v1/webhooks/{id}', ['id' => $id]);
    }

    /**
     * Change a webhook.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/webhooks/{id}', ['id' => $id], [], $params);
    }

    /**
     * Delete a webhook. Returns null.
     */
    public function remove(string $id): null
    {
        return $this->http->callNull('DELETE', '/v1/webhooks/{id}', ['id' => $id]);
    }

    /**
     * Recent delivery attempts.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function deliveries(string $id, array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/webhooks/{id}/deliveries', ['id' => $id], $query);
    }

    /**
     * Events that could not be delivered.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function deadLetters(string $id, array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/webhooks/{id}/dead-letters', ['id' => $id], $query);
    }

    /**
     * Replay a dead letter.
     *
     * @return array<mixed>
     */
    public function replay(string $deadLetterId): array
    {
        return $this->http->callArray('POST', '/v1/webhooks/dead-letters/{dlid}/replay', ['dlid' => $deadLetterId]);
    }

    /**
     * Discard a dead letter. Returns null.
     */
    public function discard(string $deadLetterId): null
    {
        return $this->http->callNull('DELETE', '/v1/webhooks/dead-letters/{dlid}', ['dlid' => $deadLetterId]);
    }

    /**
     * Reactivate a webhook the API disabled.
     *
     * @return array<mixed>
     */
    public function reactivate(string $id): array
    {
        return $this->http->callArray('POST', '/v1/webhooks/{id}/reactivate', ['id' => $id]);
    }

    /**
     * Rotate the signing secret. The response carries the new one once.
     *
     * @return array<mixed>
     */
    public function rotateSecret(string $id): array
    {
        return $this->http->callArray('POST', '/v1/webhooks/{id}/rotate-secret', ['id' => $id]);
    }

    /**
     * Check an incoming webhook. See {@see Webhook::verify()}.
     *
     * @param array<string, string|list<string>> $headers
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     * @throws SignatureVerificationException
     */
    public function verify(string $rawBody, array $headers, string $secret, int $toleranceSeconds = 300): array
    {
        return Webhook::verify($rawBody, $headers, $secret, $toleranceSeconds);
    }
}
