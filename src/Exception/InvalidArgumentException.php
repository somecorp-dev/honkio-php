<?php

declare(strict_types=1);

namespace Honkio\Exception;

/** A caller mistake caught before anything was sent: a missing key or secret, an empty or "." path identifier, a consent subject with both or neither of phone_number and email_address. */
class InvalidArgumentException extends \InvalidArgumentException implements HonkioException
{
}
