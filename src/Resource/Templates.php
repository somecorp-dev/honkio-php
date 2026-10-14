<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class Templates
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Create a template.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/email-templates', [], [], $params);
    }

    /**
     * One template by id or alias.
     *
     * @return array<mixed>
     */
    public function get(string $idOrAlias): array
    {
        return $this->http->callArray('GET', '/v1/email-templates/{idOrAlias}', ['idOrAlias' => $idOrAlias]);
    }

    /**
     * A page of templates.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/email-templates', [], $query);
    }

    /**
     * Change a template.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function update(string $idOrAlias, array $params): array
    {
        return $this->http->callArray('PATCH', '/v1/email-templates/{idOrAlias}', ['idOrAlias' => $idOrAlias], [], $params);
    }

    /**
     * Publish the draft.
     *
     * @return array<mixed>
     */
    public function publish(string $idOrAlias): array
    {
        return $this->http->callArray('POST', '/v1/email-templates/{idOrAlias}/publish', ['idOrAlias' => $idOrAlias]);
    }

    /**
     * Roll back to a version: `['version' => 2]`.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function rollback(string $idOrAlias, array $params): array
    {
        return $this->http->callArray('POST', '/v1/email-templates/{idOrAlias}/rollback', ['idOrAlias' => $idOrAlias], [], $params);
    }

    /**
     * A template's versions.
     *
     * @return array<mixed>
     */
    public function versions(string $idOrAlias): array
    {
        return $this->http->callArray('GET', '/v1/email-templates/{idOrAlias}/versions', ['idOrAlias' => $idOrAlias]);
    }

    /**
     * Delete a template. Returns null.
     */
    public function remove(string $idOrAlias): null
    {
        return $this->http->callNull('DELETE', '/v1/email-templates/{idOrAlias}', ['idOrAlias' => $idOrAlias]);
    }
}
