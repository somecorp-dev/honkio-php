<?php

declare(strict_types=1);

namespace Honkio\Auth;

/** A {@see Cache} that lasts as long as this object (one PHP process or request). */
final class InMemoryCache implements DeletableCache
{
    /** @var array<string, array{array<mixed>, float}> */
    private array $items = [];

    /** @param (\Closure(): float)|null $clock seconds since the epoch; tests pass their own */
    public function __construct(private readonly ?\Closure $clock = null)
    {
    }

    public function get(string $key): ?array
    {
        $item = $this->items[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item[1] <= $this->now()) {
            unset($this->items[$key]);

            return null;
        }

        return $item[0];
    }

    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $this->items[$key] = [$value, $this->now() + max(0, $ttlSeconds)];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
