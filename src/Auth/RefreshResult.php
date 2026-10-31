<?php

declare(strict_types=1);

namespace Honkio\Auth;

/**
 * What {@see TokenRefresher::refresh()} decided.
 *
 * - `valid`: the access token is still good; nothing was sent.
 * - `refreshed`: HonkIO issued new tokens; store $tokens in place of the old ones.
 * - `kept`: the session stays signed in on the old tokens for this request,
 *   and the next request tries again; never more than 24 hours past the
 *   access token's expiry. $reason says why: `unreachable` (a connection
 *   error, a timeout, a 5xx: HonkIO is down, see isOffline()) or `busy`
 *   (HonkIO answered 429 or 408: it is shedding load and has neither
 *   refreshed nor refused; not an outage).
 * - `ended`: sign the user out now. $reason says why: `rejected` (HonkIO
 *   refused the refresh: the session was revoked, the user blocked or
 *   deleted, removed from the organization; $error carries the OAuth error),
 *   `outage_limit` (HonkIO has not refreshed the tokens in the 24 hours past
 *   their expiry),
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

    /** HonkIO can't be reached: keep the old tokens (the outage rule). */
    public static function kept(TokenSet $tokens): self
    {
        return new self(self::KEPT, $tokens, 'unreachable');
    }

    /** HonkIO answered 429 or 408: keep the old tokens for this request only; it is not an outage. */
    public static function busy(TokenSet $tokens): self
    {
        return new self(self::KEPT, $tokens, 'busy');
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

    /** True when the session is kept because HonkIO can't be reached (not when it answered 429 or 408). */
    public function isOffline(): bool
    {
        return $this->status === self::KEPT && $this->reason === 'unreachable';
    }

    /** True when the stored tokens must be replaced with $tokens. */
    public function changed(): bool
    {
        return $this->status === self::REFRESHED;
    }
}
