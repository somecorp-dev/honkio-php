<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Http\HttpClient;

final class Notify
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Send one notification to one of your users: push, SMS and email in order until one delivers.
     * `user_id` is required. `text` is the SMS, the push body and the email's plain text; or send a
     * stored template's parts with `template` (its id or alias), `variables` and `language`
     * (`en` or `fr`). Other fields: `channels` (the order to try, each at most once), `title`,
     * `subject`, `html`, `from` (`['sms' => ..., 'email' => ...]`), `push_app_id` and `is_commercial`
     * (default false). Only the channel that delivers is billed; a channel that fell through costs
     * nothing. The notification comes back `pending` while a push or an email is being sent. The same
     * Idempotency-Key returns the first notification.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function send(array $params, ?string $idempotencyKey = null): array
    {
        return $this->http->callArray('POST', '/v1/notify', [], [], $params, $idempotencyKey, false, ['variables']);
    }
}
