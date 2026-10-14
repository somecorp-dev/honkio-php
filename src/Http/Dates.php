<?php

declare(strict_types=1);

namespace Honkio\Http;

/**
 * The few API fields declared `format: date` (not date-time) take a plain
 * Y-m-d. A DateTimeInterface passed for one is sent as its date in UTC.
 */
final class Dates
{
    /**
     * @param array<string, mixed> $params
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function dateOnly(array $params, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $params[$key] ?? null;
            if ($value instanceof \DateTimeInterface) {
                $params[$key] = \DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d');
            }
        }

        return $params;
    }
}
