<?php

declare(strict_types=1);

namespace Honkio\Exception;

/** No response arrived: DNS, connect, TLS or timeout. The request may or may not have reached the API, so retry a send only with an idempotency key. */
class ConnectionException extends \RuntimeException implements HonkioException
{
}
