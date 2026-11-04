<?php

declare(strict_types=1);

namespace Honkio\Resource\Push;

use Honkio\Http\HttpClient;

/** Payload encryption for an app. Live keys only. */
final class PushEncryption
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Turn payload encryption on or off for an app.
     *
     * @return array<mixed>
     */
    public function set(string $id, bool $enabled): array
    {
        return $this->http->callArray('POST', '/v1/push/apps/{id}/encryption', ['id' => $id], [], ['enabled' => $enabled]);
    }

    /**
     * The active and next keys, base64. Live secret keys only.
     *
     * @return array<mixed>
     */
    public function listKeys(string $id): array
    {
        return $this->http->callArray('GET', '/v1/push/apps/{id}/encryption/keys', ['id' => $id]);
    }

    /**
     * Create the next key. Returns its `kid`; read the material with listKeys().
     *
     * @return array<mixed>
     */
    public function createKey(string $id): array
    {
        return $this->http->callArray('POST', '/v1/push/apps/{id}/encryption/keys', ['id' => $id]);
    }

    /**
     * Make the next key the active one; the previous active key is retired.
     *
     * @return array<mixed>
     */
    public function activateKey(string $id, string $kid): array
    {
        return $this->http->callArray('POST', '/v1/push/apps/{id}/encryption/keys/{kid}/activate', ['id' => $id, 'kid' => $kid]);
    }
}
