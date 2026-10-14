<?php

declare(strict_types=1);

namespace Honkio\Exception;

/** A webhook failed verification: bad, missing or stale signature or timestamp, or a signed body that is not a JSON object. Answer 400 and do not process it. */
class SignatureVerificationException extends \RuntimeException implements HonkioException
{
}
