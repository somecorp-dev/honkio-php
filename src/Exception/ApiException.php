<?php

declare(strict_types=1);

namespace Honkio\Exception;

/**
 * The API answered with an error status (or with a 2xx body that is not JSON).
 *
 * PHP exception codes must be integers, so getCode() is the HTTP status and
 * the API's own string code (NO_CONSENT, VERIFICATION_INVALID_CODE, ...) is
 * $errorCode.
 */
class ApiException extends \RuntimeException implements HonkioException
{
    /**
     * @param mixed $details The envelope's `details`, or any fields the API sent beside the envelope.
     */
    final public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /** The subclass for an HTTP status; plain ApiException for any other status. */
    public static function create(string $errorCode, string $message, int $statusCode, mixed $details = null): self
    {
        $class = match (true) {
            $statusCode === 400 => BadRequestException::class,
            $statusCode === 401 => AuthenticationException::class,
            $statusCode === 402 => PaymentRequiredException::class,
            $statusCode === 403 => PermissionDeniedException::class,
            $statusCode === 404 => NotFoundException::class,
            $statusCode === 409 => ConflictException::class,
            $statusCode === 422 => UnprocessableEntityException::class,
            $statusCode === 429 => RateLimitException::class,
            $statusCode === 451 => UnavailableForLegalReasonsException::class,
            $statusCode >= 500 && $statusCode <= 599 => InternalServerException::class,
            default => self::class,
        };

        return new $class($errorCode, $message, $statusCode, $details);
    }
}
