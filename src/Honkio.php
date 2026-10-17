<?php

declare(strict_types=1);

namespace Honkio;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Http\HttpClient;
use Honkio\Resource\AutoTopup;
use Honkio\Resource\Batch;
use Honkio\Resource\BusinessVerification;
use Honkio\Resource\ConsentBatches;
use Honkio\Resource\Consents;
use Honkio\Resource\Domains;
use Honkio\Resource\Emails;
use Honkio\Resource\Messages;
use Honkio\Resource\OptInConfirmations;
use Honkio\Resource\PhoneNumbers;
use Honkio\Resource\Suppressions;
use Honkio\Resource\Templates;
use Honkio\Resource\TollFreeVerifications;
use Honkio\Resource\TopupCapRequests;
use Honkio\Resource\Verify;
use Honkio\Resource\Webhooks;

/**
 * The HonkIO client.
 *
 * ```php
 * $honkio = new Honkio\Honkio();   // reads HONKIO_API_KEY
 * $honkio->messages->send(['from' => '+1416XXXXXXX', 'to' => '+1613XXXXXXX', 'body' => 'Hi']);
 * ```
 */
final class Honkio
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.honkio.ca';

    public readonly Emails $emails;
    public readonly Batch $batch;
    public readonly Domains $domains;
    public readonly Suppressions $suppressions;
    public readonly Templates $templates;
    public readonly Messages $messages;
    public readonly PhoneNumbers $phoneNumbers;
    public readonly TollFreeVerifications $tollFreeVerifications;
    public readonly BusinessVerification $businessVerification;
    public readonly AutoTopup $autoTopup;
    public readonly TopupCapRequests $topupCapRequests;
    public readonly Verify $verify;
    public readonly Consents $consents;
    public readonly ConsentBatches $consentBatches;
    public readonly OptInConfirmations $optInConfirmations;
    public readonly Webhooks $webhooks;

    /**
     * @param string|null $apiKey Falls back to the HONKIO_API_KEY environment variable.
     * @param array{base_url?: string, timeout?: float|int, http_client?: ClientInterface, max_retries?: int, max_retry_delay?: float|int} $options
     *        base_url defaults to https://api.honkio.ca; timeout (seconds) to 30;
     *        http_client to a new Guzzle client. max_retries (default 2, 0 turns
     *        retrying off) is how often a refusal that waiting fixes
     *        (NUMBER_RATE_LIMITED, CARRIER_THROTTLED, CARRIER_UNAVAILABLE,
     *        RATE_LIMITED) or a connection error is retried; a wait longer than
     *        max_retry_delay seconds (default 60) is not waited out.
     *
     * @throws InvalidArgumentException when there is no API key, or a retry option is negative.
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $key = $apiKey;
        if ($key === null || $key === '') {
            $env = getenv('HONKIO_API_KEY');
            $key = is_string($env) ? $env : '';
        }
        // A programming error: fail at construction, not on the first call.
        if ($key === '') {
            throw new InvalidArgumentException('Missing HonkIO API key: pass it to new Honkio($key) or set HONKIO_API_KEY.');
        }

        $baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $client = $options['http_client'] ?? new Client();
        $http = new HttpClient(
            $key,
            $baseUrl,
            $client,
            (float) ($options['timeout'] ?? 30.0),
            $options['max_retries'] ?? HttpClient::DEFAULT_MAX_RETRIES,
            (float) ($options['max_retry_delay'] ?? HttpClient::DEFAULT_MAX_RETRY_DELAY),
        );

        $this->emails = new Emails($http);
        $this->batch = new Batch($http);
        $this->domains = new Domains($http);
        $this->suppressions = new Suppressions($http);
        $this->templates = new Templates($http);
        $this->messages = new Messages($http);
        $this->phoneNumbers = new PhoneNumbers($http);
        $this->tollFreeVerifications = new TollFreeVerifications($http);
        $this->businessVerification = new BusinessVerification($http);
        $this->autoTopup = new AutoTopup($http);
        $this->topupCapRequests = new TopupCapRequests($http);
        $this->verify = new Verify($http);
        $this->consents = new Consents($http);
        $this->consentBatches = new ConsentBatches($http);
        $this->optInConfirmations = new OptInConfirmations($http);
        $this->webhooks = new Webhooks($http);
    }
}
