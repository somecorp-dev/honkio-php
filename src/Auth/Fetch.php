<?php

declare(strict_types=1);

namespace Honkio\Auth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Honkio\Exception\IssuerUnavailableException;
use Honkio\Honkio;

/**
 * GETs a public JSON document from the issuer (discovery, JWKS). Any failure
 * is an IssuerUnavailableException, which callers answer from their cache.
 *
 * @internal
 */
final class Fetch
{
    /**
     * @return array<mixed>
     *
     * @throws IssuerUnavailableException
     */
    public static function json(ClientInterface $http, string $url, float $timeout): array
    {
        try {
            $response = $http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'honkio-php/' . Honkio::VERSION],
                'http_errors' => false,
                'allow_redirects' => false,
                'timeout' => $timeout,
                'connect_timeout' => min($timeout, 5.0),
            ]);
        } catch (GuzzleException $e) {
            throw new IssuerUnavailableException("Could not reach $url: " . $e->getMessage(), 0, $e);
        }
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new IssuerUnavailableException("$url answered HTTP $status.");
        }
        try {
            $body = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new IssuerUnavailableException("$url did not answer with JSON.", 0, $e);
        }
        if (!is_array($body) || array_is_list($body)) {
            throw new IssuerUnavailableException("$url did not answer with a JSON object.");
        }

        return $body;
    }

    /** An https URL, or plain http on localhost (a local HonkIO for development). */
    public static function isSecureUrl(mixed $url): bool
    {
        if (!is_string($url) || $url === '') {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }
}
