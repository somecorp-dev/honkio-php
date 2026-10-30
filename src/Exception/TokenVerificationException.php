<?php

declare(strict_types=1);

namespace Honkio\Exception;

/**
 * A Honkio Auth token failed a check: treat the caller as signed out. The
 * message never contains the token. $reason is one of malformed, too_large,
 * unsupported_algorithm, wrong_type, unknown_key, bad_signature,
 * wrong_issuer, wrong_audience, expired, not_yet_valid, missing_subject or
 * nonce_mismatch.
 */
class TokenVerificationException extends \RuntimeException implements HonkioException
{
    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
