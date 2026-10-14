<?php

declare(strict_types=1);

namespace Honkio\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Honkio\Honkio;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle client backed by a MockHandler that records every request.
 */
final class MockHttp
{
    /** @var array<int, array{request: RequestInterface, response: mixed, error: mixed, options: array<string, mixed>}> */
    public array $history = [];

    public readonly Client $client;

    /** @param list<mixed> $queue Responses or throwables, in order. */
    public function __construct(array $queue = [])
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $this->client = new Client(['handler' => $stack]);
    }

    /**
     * @param list<mixed> $queue
     * @return array{Honkio, MockHttp}
     */
    public static function honkio(array $queue, string $key = 'k'): array
    {
        $mock = new self($queue);

        return [new Honkio($key, ['http_client' => $mock->client]), $mock];
    }

    public static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function request(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    public function count(): int
    {
        return count($this->history);
    }

    /** @return array<mixed> */
    public function jsonBody(int $index = 0): array
    {
        $decoded = json_decode((string) $this->request($index)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        return $decoded;
    }
}
