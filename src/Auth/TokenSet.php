<?php

declare(strict_types=1);

namespace Honkio\Auth;

use Honkio\Exception\InvalidArgumentException;

/**
 * A signed-in user's tokens, as you keep them in your server-side session.
 * toArray() and fromArray() round-trip it through a session store. It never
 * belongs in a cookie the browser can read, or in a log: var_dump() and
 * print_r() show it with the tokens redacted.
 */
final class TokenSet
{
    /**
     * @param int $expiresAt Unix time the access token expires (its `exp`)
     * @param string|null $subject the user's id (`sub`), checked to stay the same on every refresh
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?string $idToken,
        public readonly int $expiresAt,
        public readonly ?string $subject = null,
        public readonly string $scope = '',
    ) {
    }

    /**
     * @param array<mixed> $data what toArray() returned
     *
     * @throws InvalidArgumentException when it isn't a stored token set
     */
    public static function fromArray(array $data): self
    {
        $access = $data['access_token'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;
        if (!is_string($access) || $access === '' || !is_int($expiresAt)) {
            throw new InvalidArgumentException('Not a stored HonkIO Auth token set (access_token and expires_at are required).');
        }
        $string = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        return new self(
            $access,
            $string($data['refresh_token'] ?? null),
            $string($data['id_token'] ?? null),
            $expiresAt,
            $string($data['sub'] ?? null),
            is_string($data['scope'] ?? null) ? $data['scope'] : '',
        );
    }

    /** @return array{access_token: string, refresh_token: ?string, id_token: ?string, expires_at: int, sub: ?string, scope: string} */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'id_token' => $this->idToken,
            'expires_at' => $this->expiresAt,
            'sub' => $this->subject,
            'scope' => $this->scope,
        ];
    }

    /** True once the access token has expired, or will within $skew seconds. */
    public function isExpired(int $now, int $skew = 30): bool
    {
        return $now + $skew >= $this->expiresAt;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '[redacted]',
            'refreshToken' => $this->refreshToken === null ? null : '[redacted]',
            'idToken' => $this->idToken === null ? null : '[redacted]',
            'expiresAt' => $this->expiresAt,
            'subject' => $this->subject,
            'scope' => $this->scope,
        ];
    }
}
