<?php

declare(strict_types=1);

namespace Honkio\Http;

use Honkio\Exception\InvalidArgumentException;

final class Path
{
    /**
     * Fills `{name}` placeholders, one encoded segment each. An empty, "." or
     * ".." identifier is refused: a client would collapse `/v1/email-domains/..`
     * into `/v1/`, so the request would land on a different resource.
     *
     * @param array<string, string> $params
     */
    public static function fill(string $template, array $params): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($template, $params): string {
            $value = $params[$m[1]] ?? null;
            if (!is_string($value) || $value === '' || $value === '.' || $value === '..') {
                throw new InvalidArgumentException(
                    sprintf('An identifier for %s was empty, "." or ".."; nothing was sent.', $template),
                );
            }

            return rawurlencode($value);
        }, $template);
    }
}
