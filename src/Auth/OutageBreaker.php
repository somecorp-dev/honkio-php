<?php

declare(strict_types=1);

namespace Honkio\Auth;

/**
 * Whether one app's issuer looks down. It does once {@see TRIP_SESSIONS}
 * different sessions in a row could not reach it (a connection error, a
 * timeout, a 5xx, an unreadable answer) within {@see TRIP_WINDOW_SECONDS};
 * then, for {@see PAUSE_SECONDS}, refreshes keep their sessions without
 * calling Honkio and cached discovery and keys are used without refetching,
 * so requests don't each wait on a timeout. Any answer from Honkio (a 408 or
 * 429 included) resets the count, so one session, or a busy Honkio, never
 * pauses the others.
 *
 * The state lives in the {@see Cache} under a key for this issuer and client
 * id, so it is shared by every PHP process using the same cache and never
 * spills over to another app (another issuer, or another client on it).
 *
 * @internal
 */
final class OutageBreaker
{
    public const PAUSE_SECONDS = 15;
    public const TRIP_SESSIONS = 3;
    public const TRIP_WINDOW_SECONDS = 10;

    private readonly string $key;

    /** @param \Closure(): int $clock */
    public function __construct(
        private readonly Cache $cache,
        string $issuer,
        string $clientId,
        private readonly \Closure $clock,
    ) {
        $this->key = 'honkio-auth:outage:' . hash('sha256', $issuer . "\n" . $clientId);
    }

    public function paused(): bool
    {
        $until = $this->state()['paused_until'];

        return $until > ($this->clock)();
    }

    /** A refresh for $session (a hash of its refresh token) could not reach Honkio. */
    public function unreachable(string $session): void
    {
        $now = ($this->clock)();
        $state = $this->state();
        $recent = array_filter($state['recent'], static fn (int $at): bool => $now - $at <= self::TRIP_WINDOW_SECONDS);
        $recent[$session] = $now;
        if (count($recent) >= self::TRIP_SESSIONS) {
            $this->cache->set($this->key, ['recent' => [], 'paused_until' => $now + self::PAUSE_SECONDS], self::PAUSE_SECONDS);

            return;
        }
        $this->cache->set($this->key, ['recent' => $recent, 'paused_until' => $state['paused_until']], self::TRIP_WINDOW_SECONDS + self::PAUSE_SECONDS);
    }

    /** Honkio answered (anything but a 5xx): the run of failures is over. */
    public function answered(): void
    {
        $state = $this->state();
        if ($state['recent'] !== []) {
            $this->cache->set($this->key, ['recent' => [], 'paused_until' => $state['paused_until']], self::PAUSE_SECONDS);
        }
    }

    /** @return array{recent: array<string, int>, paused_until: int} */
    private function state(): array
    {
        $cached = $this->cache->get($this->key);
        $recent = [];
        foreach (is_array($cached['recent'] ?? null) ? $cached['recent'] : [] as $session => $at) {
            if (is_string($session) && is_int($at)) {
                $recent[$session] = $at;
            }
        }
        $until = $cached['paused_until'] ?? 0;

        return ['recent' => $recent, 'paused_until' => is_int($until) ? $until : 0];
    }
}
