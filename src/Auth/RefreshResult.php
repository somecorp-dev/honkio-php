<?php

declare(strict_types=1);

namespace Honkio\Auth;

/**
 * What {@see TokenRefresher::refresh()} decided.
 *
 * - `valid`: the access token is still good; nothing was sent.
 * - `refreshed`: Honkio issued new tokens; store $tokens in place of the old ones.
 * - `kept`: Honkio could not be reached (a connection error, a timeout, a
 *   5xx, a 429); the session stays signed in on the old tokens, and the next
 *   request tries again. This lasts at most 24 hours past the access token's expiry.
 * - `ended`: sign the user out now. $reason says why: `rejected` (Honkio
 *   refused the refresh: the session was revoked, the user blocked or
 *   deleted, removed from the organization; $error carries the OAuth error),
 *   `outage_limit` (Honkio has been unreachable for 24 hours past expiry),
 *   `no_refresh_token`, `invalid_response` (the new tokens failed their
 *   checks) or `subject_changed` (the new tokens name another user).
 */
final class RefreshResult
{
    public const VALID = 'valid';
    public const REFRESHED = 'refreshed';
    public const KEPT = 'kept';
    public const ENDED = 'ended';

    private function __construct(
        public readonly string $status,
        public readonly ?TokenSet $tokens,
        public readonly ?string $reason = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function valid(TokenSet $tokens): self
    {
        return new self(self::VALID, $tokens);
    }

    public static function refreshed(TokenSet $tokens): self
    {
        return new self(self::REFRESHED, $tokens);
    }

    public static function kept(TokenSet $tokens): self
    {
        return new self(self::KEPT, $tokens, 'unreachable');
    }

    public static function ended(string $reason, ?string $error = null): self
    {
        return new self(self::ENDED, null, $reason, $error);
    }

    /** True unless the user must be signed out. */
    public function isActive(): bool
    {
        return $this->status !== self::ENDED;
    }

    /** True when the stored tokens must be replaced with $tokens. */
    public function changed(): bool
    {
        return $this->status === self::REFRESHED;
    }
}
