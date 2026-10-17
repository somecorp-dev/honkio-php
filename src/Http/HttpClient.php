<?php

declare(strict_types=1);

namespace Honkio\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Honkio\Exception\ApiException;
use Honkio\Exception\ConnectionException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Honkio;

/**
 * The one place a request is built and sent. A refusal that waiting fixes,
 * or a connection error on a request that is safe to repeat, is retried up
 * to $maxRetries times; a send to a keyed route always carries an
 * Idempotency-Key, so a retry can never send or charge twice.
 */
final class HttpClient
{
    public const DEFAULT_MAX_RETRIES = 2;
    public const DEFAULT_MAX_RETRY_DELAY = 60.0;

    /**
     * The routes that honour Idempotency-Key (api/src/routes/messages and
     * emails). A call without a key gets a generated one, kept across its retries.
     */
    private const KEYED_ROUTES = ['POST /v1/messages', 'POST /v1/emails', 'POST /v1/emails/batch'];

    /** Nothing was sent or charged, and waiting is the fix: safe to retry on any route. */
    private const RETRY_ALWAYS = ['NUMBER_RATE_LIMITED', 'CARRIER_UNAVAILABLE', 'CARRIER_THROTTLED'];

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param int $maxRetries retries after the first attempt; 0 turns retrying off
     * @param float $maxRetryDelay seconds; a longer wait is not waited out and the error is thrown
     * @param (\Closure(float): void)|null $sleep how to wait, in seconds; tests pass one that does not
     *
     * @throws InvalidArgumentException for a negative $maxRetries or $maxRetryDelay
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ClientInterface $client,
        private readonly float $timeout = 30.0,
        private readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        private readonly float $maxRetryDelay = self::DEFAULT_MAX_RETRY_DELAY,
        ?\Closure $sleep = null,
    ) {
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('max_retries must be 0 or more.');
        }
        if ($maxRetryDelay < 0 || !is_finite($maxRetryDelay)) {
            throw new InvalidArgumentException('max_retry_delay must be a number of seconds, 0 or more.');
        }
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * Sends one JSON request and returns the decoded response (null for an empty one).
     *
     * @param array<string, string> $pathParams
     * @param array<string, mixed> $query
     * @param array<mixed>|null $body null sends no body
     * @param list<string> $objectKeys keys whose value is a caller-owned map (the email `variables` and `headers`): an empty one is sent as {}, not []
     *
     * @throws ApiException
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function call(
        string $method,
        string $template,
        array $pathParams = [],
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
        bool $bodyIsList = false,
        array $objectKeys = [],
    ): mixed {
        return $this->execute($method, $template, $pathParams, $query, $body, $idempotencyKey, $bodyIsList, $objectKeys)[1];
    }

    /**
     * @param array<string, string> $pathParams
     * @param array<string, mixed> $query
     * @param array<mixed>|null $body
     * @param list<string> $objectKeys
     * @return array{int, mixed} the HTTP status and the decoded response
     */
    private function execute(
        string $method,
        string $template,
        array $pathParams,
        array $query,
        ?array $body,
        ?string $idempotencyKey,
        bool $bodyIsList,
        array $objectKeys = [],
    ): array {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'honkio-php/' . Honkio::VERSION,
        ];
        $options = ['headers' => $headers];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $normalized = self::normalize($body, $objectKeys);
            // The API expects objects: an empty map is {} (a batch body is a list by design).
            if ($normalized === [] && !$bodyIsList) {
                $normalized = new \stdClass();
            }
            try {
                $options['body'] = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('The request body is not valid UTF-8; nothing was sent.', 0, $e);
            }
        }
        // The caller's key wins; otherwise one per call, the same on every retry.
        if (($idempotencyKey === null || $idempotencyKey === '') && in_array("$method $template", self::KEYED_ROUTES, true)) {
            $idempotencyKey = self::uuid4();
        }
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }

        $safeToRepeat = $method === 'GET' || isset($options['headers']['Idempotency-Key']);

        return $this->sendWithRetries(
            $method,
            $this->url($template, $pathParams, $query),
            $options,
            $safeToRepeat,
            static fn (int $status, string $text): array => [$status, ResponseParser::parse($status, $text)],
        );
    }

    /**
     * call() for routes that answer with a JSON object or list.
     *
     * @param array<string, string> $pathParams
     * @param array<string, mixed> $query
     * @param array<mixed>|null $body
     * @param list<string> $objectKeys see call()
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function callArray(
        string $method,
        string $template,
        array $pathParams = [],
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
        bool $bodyIsList = false,
        array $objectKeys = [],
    ): array {
        [$status, $result] = $this->execute($method, $template, $pathParams, $query, $body, $idempotencyKey, $bodyIsList, $objectKeys);
        if (!is_array($result)) {
            throw ApiException::create('application_error', 'The API answered with JSON that is not an object or a list.', $status);
        }

        return $result;
    }

    /**
     * call() for a route whose body may be JSON null (business verification before a profile exists).
     *
     * @param array<string, string> $pathParams
     * @return array<mixed>|null
     *
     * @throws ApiException
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function callNullableArray(string $method, string $template, array $pathParams = []): ?array
    {
        [$status, $result] = $this->execute($method, $template, $pathParams, [], null, null, false);
        if ($result !== null && !is_array($result)) {
            throw ApiException::create('application_error', 'The API answered with JSON that is not an object or a list.', $status);
        }

        return $result;
    }

    /**
     * call() for deletes: the API answers 204 with no body, and the result is null.
     *
     * @param array<string, string> $pathParams
     *
     * @throws ApiException
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function callNull(string $method, string $template, array $pathParams = []): null
    {
        $this->call($method, $template, $pathParams);

        return null;
    }

    /**
     * Like call(), but for a binary body (raw MIME, an attachment): returns it
     * as a string. A non-2xx status is still the API's JSON error envelope.
     *
     * @param array<string, string> $pathParams
     *
     * @throws ApiException
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function callRaw(string $method, string $template, array $pathParams = []): string
    {
        $options = ['headers' => [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'honkio-php/' . Honkio::VERSION,
        ]];

        return $this->sendWithRetries(
            $method,
            $this->url($template, $pathParams, []),
            $options,
            $method === 'GET',
            static function (int $status, string $text): string {
                if ($status >= 200 && $status < 300) {
                    return $text;
                }

                ResponseParser::parse($status, $text);
                // parse() always throws for a non-2xx status.
                throw ApiException::create('application_error', "HTTP $status", $status);
            },
        );
    }

    /**
     * Sends until $parse returns, the error is not worth retrying, the retries
     * run out or the wait would be longer than allowed; then the last error is
     * thrown as it is.
     *
     * @template T
     * @param array<string, mixed> $options
     * @param callable(int, string): T $parse
     * @return T
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    private function sendWithRetries(string $method, string $url, array $options, bool $safeToRepeat, callable $parse): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            $retryAfter = null;
            try {
                [$status, $text, $retryAfter] = $this->send($method, $url, $options);

                return $parse($status, $text);
            } catch (ApiException|ConnectionException $e) {
                $wait = $this->retryWait($e, $safeToRepeat, $attempt, $retryAfter);
                if ($wait === null) {
                    throw $e;
                }
            }
            ($this->sleep)($wait);
        }
    }

    /**
     * Seconds to wait before retrying, or null to throw. RATE_LIMITED is also
     * a route's own limit (one verify start per recipient a minute), where a
     * repeat sends again, and a connection error may have reached the API:
     * both are retried only when repeating is harmless, a GET or a request
     * with an idempotency key. CARRIER_TIMEOUT is never retried: the message
     * may have gone out.
     */
    private function retryWait(ApiException|ConnectionException $e, bool $safeToRepeat, int $attempt, ?string $retryAfter): ?float
    {
        if ($attempt >= $this->maxRetries) {
            return null;
        }
        $retry = $e instanceof ConnectionException
            ? $safeToRepeat
            : in_array($e->errorCode, self::RETRY_ALWAYS, true) || ($e->errorCode === 'RATE_LIMITED' && $safeToRepeat);
        if (!$retry) {
            return null;
        }
        $wait = self::retryDelay($retryAfter, $e instanceof ApiException ? $e->details : null, $attempt);

        return $wait > $this->maxRetryDelay ? null : $wait;
    }

    /**
     * Retry-After (seconds), else details.retry_after_seconds, else
     * exponential backoff with jitter.
     *
     * @param (callable(): float)|null $random a number from 0 to 1
     */
    public static function retryDelay(?string $retryAfter, mixed $details, int $attempt, ?callable $random = null): float
    {
        if ($retryAfter !== null && is_numeric(trim($retryAfter))) {
            $seconds = (float) trim($retryAfter);
            if ($seconds >= 0 && is_finite($seconds)) {
                return $seconds;
            }
        }
        $given = is_array($details) ? ($details['retry_after_seconds'] ?? null) : null;
        if ((is_int($given) || is_float($given)) && $given >= 0 && is_finite((float) $given)) {
            return (float) $given;
        }
        $base = 0.5 * 2 ** $attempt;
        $r = $random !== null ? $random() : mt_rand() / mt_getrandmax();

        return $base / 2 + $r * ($base / 2);
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, string, ?string} the status, the body and the Retry-After header
     */
    private function send(string $method, string $url, array $options): array
    {
        $options['http_errors'] = false;
        $options['timeout'] = $this->timeout;
        try {
            $response = $this->client->request($method, $url, $options);

            return [
                $response->getStatusCode(),
                (string) $response->getBody(),
                $response->hasHeader('Retry-After') ? $response->getHeaderLine('Retry-After') : null,
            ];
        } catch (GuzzleException $e) {
            throw new ConnectionException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, string> $pathParams
     * @param array<string, mixed> $query
     */
    private function url(string $template, array $pathParams, array $query): string
    {
        $url = $this->baseUrl . Path::fill($template, $pathParams);
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value) && !array_is_list($value)) {
                throw new InvalidArgumentException("The query value for $key must be a scalar or a list, not a map; nothing was sent.");
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode(self::queryValue($item));
            }
        }

        return $pairs === [] ? $url : $url . '?' . implode('&', $pairs);
    }

    private static function queryValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => self::iso($value),
            is_int($value), is_float($value), is_string($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => throw new InvalidArgumentException('A query value must be a string, number, bool, DateTimeInterface or a list of them.'),
        };
    }

    private static function iso(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Turns datetimes into ISO 8601. Null values are kept and sent as JSON null
     * (the caller clears a nullable field by passing null, and leaves a field
     * alone by omitting the key). The values under $objectKeys are the caller's
     * own maps and stay as given; when empty they are objects.
     *
     * @param list<string> $objectKeys
     */
    private static function normalize(mixed $value, array $objectKeys): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return self::iso($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalize($item, $objectKeys), $value);
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (in_array($key, $objectKeys, true)) {
                // Always an object, even when empty.
                $out[$key] = $item === [] ? new \stdClass() : $item;
            } else {
                $out[$key] = self::normalize($item, $objectKeys);
            }
        }

        return $out;
    }
}
