<?php

declare(strict_types=1);

namespace Honkio\Resource;

use Honkio\Exception\InvalidArgumentException;
use Honkio\Http\Dates;
use Honkio\Http\HttpClient;

final class Consents
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Record consent for a phone number or an email address. `last_transaction_date`
     * is a date: a string `YYYY-MM-DD`, or a DateTimeInterface (sent as its UTC date).
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        return $this->http->callArray('POST', '/v1/compliance/consents', [], [], Dates::dateOnly($params, ['last_transaction_date']));
    }

    /**
     * A page of consent records.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->callArray('GET', '/v1/compliance/consents', [], $query);
    }

    /**
     * Record consent for many subjects at once.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function recordBatch(array $params): array
    {
        return $this->http->callArray('POST', '/v1/compliance/consents/batch', [], [], $params);
    }

    /**
     * Is there consent on file? Pass exactly one of `phone_number` or `email_address`.
     *
     * @param array{phone_number?: string|null, email_address?: string|null} $subject
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public function check(array $subject): array
    {
        [$kind, $value] = self::subject($subject);

        return $this->http->callArray('GET', '/v1/compliance/consents/check', [], [$kind => $value]);
    }

    /**
     * Revoke consent. Pass exactly one of `phone_number` or `email_address`. Returns null.
     *
     * @param array{phone_number?: string|null, email_address?: string|null} $subject
     * @throws InvalidArgumentException
     */
    public function revoke(array $subject): null
    {
        [$kind, $value] = self::subject($subject);
        if ($kind === 'phone_number') {
            return $this->http->callNull('DELETE', '/v1/compliance/consents/{phone}', ['phone' => $value]);
        }

        return $this->http->callNull('DELETE', '/v1/compliance/consents/email/{address}', ['address' => $value]);
    }

    /**
     * @param array<string, mixed> $subject
     * @return array{string, string}
     * @throws InvalidArgumentException
     */
    private static function subject(array $subject): array
    {
        $phone = $subject['phone_number'] ?? null;
        $email = $subject['email_address'] ?? null;
        $hasPhone = is_string($phone) && $phone !== '';
        $hasEmail = is_string($email) && $email !== '';
        if ($hasPhone === $hasEmail) {
            throw new InvalidArgumentException('Pass exactly one of phone_number or email_address; nothing was sent.');
        }

        return $hasPhone ? ['phone_number', $phone] : ['email_address', (string) $email];
    }
}
