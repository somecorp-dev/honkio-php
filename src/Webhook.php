<?php

declare(strict_types=1);

namespace Honkio;

use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\SignatureVerificationException;

final class Webhook
{
    /**
     * Checks X-HonkIO-Signature: HMAC-SHA256, keyed with the signing secret as
     * UTF-8 (not hex-decoded), over "{X-HonkIO-Timestamp}.{raw body}". Pass the
     * raw body exactly as received, before any JSON parsing.
     *
     * Header names are matched case-insensitively. A value may be a string or
     * a list of strings (the PSR-7 and Laravel shape); the first is used.
     *
     * @param array<string, string|list<string>> $headers
     * @return array<string, mixed> the decoded event
     *
     * @throws InvalidArgumentException a missing secret
     * @throws SignatureVerificationException anything about the headers, signature, timestamp or body
     */
    public static function verify(string $rawBody, array $headers, string $secret, int $toleranceSeconds = 300): array
    {
        if ($secret === '') {
            throw new InvalidArgumentException('secret is missing or empty. Read it from your webhook settings and keep it out of source control.');
        }

        $timestamp = self::header($headers, 'x-honkio-timestamp');
        $signature = self::header($headers, 'x-honkio-signature');
        if ($timestamp === null || $signature === null) {
            throw new SignatureVerificationException('Missing X-HonkIO-Timestamp or X-HonkIO-Signature.');
        }
        if (preg_match('/^\d+$/D', $timestamp) !== 1) {
            throw new SignatureVerificationException('X-HonkIO-Timestamp is not Unix epoch seconds.');
        }
        if (abs(time() - (float) $timestamp) > $toleranceSeconds) {
            throw new SignatureVerificationException(
                "The timestamp is more than $toleranceSeconds seconds from now; reject it as a possible replay.",
            );
        }
        if (preg_match('/^[0-9a-f]{64}$/Di', $signature) !== 1) {
            throw new SignatureVerificationException('X-HonkIO-Signature is not 64 hex characters.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new SignatureVerificationException('The signature does not match.');
        }

        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SignatureVerificationException('The body is signed correctly but is not JSON.');
        }
        if (!is_array($event) || ($event !== [] && array_is_list($event))) {
            throw new SignatureVerificationException('The body is signed correctly but is not a JSON object.');
        }

        return $event;
    }

    /** @param array<array-key, mixed> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }
            if (is_array($value)) {
                $value = $value === [] ? null : reset($value);
            }

            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }
}
