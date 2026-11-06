<?php

declare(strict_types=1);

namespace Honkio\Resource\Push;

use Honkio\Http\HttpClient;

/**
 * Web push (browser notifications): the app's VAPID key pair. Live keys only.
 * The public key is on the app: `$honkio->push->apps->get($id)['web']['vapid_public_key']`.
 */
final class PushWeb
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Generate a new VAPID pair (no keys given), or import yours: `public_key` and `private_key`
     * (P-256, base64url), and optionally `subject` (an https: URL or a mailto: address; null for
     * the default). The private key is never returned. Browsers that subscribed with another
     * public key must subscribe again.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function setVapidKeys(string $id, array $params = []): array
    {
        return $this->http->callArray('POST', '/v1/push/apps/{id}/web/vapid-keys', ['id' => $id], [], $params);
    }
}
