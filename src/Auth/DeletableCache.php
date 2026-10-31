<?php

declare(strict_types=1);

namespace Honkio\Auth;

/**
 * A {@see Cache} that can delete an entry. Implement it on your cache so
 * {@see HonkioAuth::revoke()} removes the revoked session's entries at once.
 * A plain Cache still works: those entries are overwritten with an empty value
 * that expires at once (Cache::set() with a TTL of 0), which every reader
 * treats as missing.
 */
interface DeletableCache extends Cache
{
    public function delete(string $key): void;
}
