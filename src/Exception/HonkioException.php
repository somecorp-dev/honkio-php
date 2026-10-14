<?php

declare(strict_types=1);

namespace Honkio\Exception;

/**
 * Implemented by every exception this SDK throws, so one
 * `catch (HonkioException $e)` covers them all.
 */
interface HonkioException extends \Throwable
{
}
