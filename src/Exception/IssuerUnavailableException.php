<?php

declare(strict_types=1);

namespace Honkio\Exception;

/**
 * The app's issuer could not be reached or answered with something unusable
 * (a connection error, a timeout, a 5xx, a discovery document for another
 * issuer), and nothing usable was cached. It says nothing about the token.
 */
class IssuerUnavailableException extends \RuntimeException implements HonkioException
{
}
