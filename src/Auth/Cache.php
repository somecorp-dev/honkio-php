<?php

declare(strict_types=1);

namespace Honkio\Auth;

/**
 * Where HonkIO Auth keeps the discovery document, the signing keys and, for a
 * few seconds, the result of each refresh. Share one across requests (APCu, Redis,
 * your framework's cache) so a HonkIO outage finds the keys already cached;
 * the default, {@see InMemoryCache}, lasts one PHP process.
 *
 * The refresh entries hold tokens: use a store only your servers can read.
 *
 * So that {@see HonkioAuth::revoke()} can delete entries, implement
 * {@see DeletableCache}, which extends this interface; a cache that only
 * implements this one keeps working.
 */
interface Cache
{
    /** @return array<mixed>|null the value, or null when missing or expired */
    public function get(string $key): ?array;

    /** @param array<mixed> $value */
    public function set(string $key, array $value, int $ttlSeconds): void;
}
