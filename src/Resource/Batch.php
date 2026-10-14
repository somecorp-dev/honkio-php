<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class Batch
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Two shapes. A list of up to 100 independent emails (each validated
     * before any is sent), or one template message to up to 500 recipients,
     * each with their own variables.
     *
     * @param array<mixed> $emailsOrTemplateBatch
     * @return array<mixed>
     */
    public function send(array $emailsOrTemplateBatch, ?string $idempotencyKey = null): array
    {
        return $this->http->callArray('POST', '/v1/emails/batch', [], [], $emailsOrTemplateBatch, $idempotencyKey, true);
    }
}
