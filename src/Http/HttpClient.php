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
 * The one place a request is built and sent. Never retries: a send costs
 * money, so retrying is the caller's choice, with an idempotency key.
 */
final class HttpClient
{
    /** Maps whose own keys are the caller's, not the API's: template variables, custom headers, metadata. */
    private const OPAQUE_KEYS = ['variables', 'headers', 'metadata'];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ClientInterface $client,
        private readonly float $timeout = 30.0,
    ) {
    }

    /**
     * Sends one JSON request and returns the decoded response (null for an empty one).
     *
     * @param array<string, string> $pathParams
     * @param array<string, mixed> $query
     * @param array<mixed>|null $body null sends no body
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
    ): mixed {
        return $this->execute($method, $template, $pathParams, $query, $body, $idempotencyKey, $bodyIsList)[1];
    }

    /**
     * @param array<string, string> $pathParams
     * @param array<string, mixed> $query
     * @param array<mixed>|null $body
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
    ): array {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'honkio-php/' . Honkio::VERSION,
        ];
        $options = ['headers' => $headers];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $normalized = self::normalize($body);
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
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }

        [$status, $text] = $this->send($method, $this->url($template, $pathParams, $query), $options);

        return [$status, ResponseParser::parse($status, $text)];
    }

    /**
     * call() for routes that answer with a JSON object or list.
     *
     * @param array<string, string> $pathParams
     * @param array<string, mixed> $query
     * @param array<mixed>|null $body
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
    ): array {
        [$status, $result] = $this->execute($method, $template, $pathParams, $query, $body, $idempotencyKey, $bodyIsList);
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

        [$status, $text] = $this->send($method, $this->url($template, $pathParams, []), $options);
        if ($status >= 200 && $status < 300) {
            return $text;
        }

        ResponseParser::parse($status, $text);
        // parse() always throws for a non-2xx status.
        throw ApiException::create('application_error', "HTTP $status", $status);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, string}
     */
    private function send(string $method, string $url, array $options): array
    {
        $options['http_errors'] = false;
        $options['timeout'] = $this->timeout;
        try {
            $response = $this->client->request($method, $url, $options);

            return [$response->getStatusCode(), (string) $response->getBody()];
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
     * Drops null values from maps and turns datetimes into ISO 8601. The values
     * under variables, headers and metadata are the caller's own and stay as given.
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return self::iso($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        $out = [];
        foreach ($value as $key => $item) {
            if ($item === null) {
                continue;
            }
            if (in_array($key, self::OPAQUE_KEYS, true)) {
                // Always an object, even when empty.
                $out[$key] = $item === [] ? new \stdClass() : $item;
            } else {
                $out[$key] = self::normalize($item);
            }
        }

        // A map emptied by null-dropping is still an object.
        return $out === [] ? new \stdClass() : $out;
    }
}
