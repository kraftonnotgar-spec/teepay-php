<?php

declare(strict_types=1);

namespace TeePay;

use TeePay\Exception\TeePayError;
use TeePay\HttpClient\CurlHttpClient;
use TeePay\HttpClient\HttpClientInterface;
use TeePay\Service\CheckoutSessions;
use TeePay\Service\Collections;
use TeePay\Service\ControlNumbers;
use TeePay\Service\Disbursements;
use TeePay\Service\Webhooks;

/**
 * TeePay API client.
 *
 *     $teepay = new \TeePay\TeePay(getenv('TEEPAY_API_KEY'));
 *     $teepay = new \TeePay\TeePay(['api_key' => '...', 'timeout' => 20, 'max_retries' => 3]);
 *
 * Keep the key on your server. It is never logged and is redacted from var_dump()/print_r().
 */
final class TeePay
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.teepay.me';
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_MAX_RETRIES = 2;
    /** The API version this SDK was built against. Send it with the api_version option to pin it. */
    public const API_VERSION = '2026-10-18';

    /** "sandbox" for tp_test_ keys, "live" for tp_live_ keys. */
    public readonly string $environment;
    public readonly Collections $collections;
    public readonly Disbursements $disbursements;
    public readonly ControlNumbers $controlNumbers;
    public readonly CheckoutSessions $checkoutSessions;
    public readonly Webhooks $webhooks;

    private readonly ApiRequestor $requestor;

    /**
     * @param string|array{api_key?: string, base_url?: string, timeout?: float|int, max_retries?: int, api_version?: string|null, http_client?: HttpClientInterface, sleeper?: callable(float): void}|null $config
     *        A secret API key, or an options array. When no key is given, TEEPAY_API_KEY is read from the environment.
     */
    public function __construct(#[\SensitiveParameter] string|array|null $config = null)
    {
        if (is_string($config)) {
            $config = ['api_key' => $config];
        }
        $config ??= [];
        $known = ['api_key', 'base_url', 'timeout', 'max_retries', 'api_version', 'http_client', 'sleeper'];
        $unknown = array_diff(array_keys($config), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown TeePay option(s): ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', $known) . '.');
        }
        $apiKey = $config['api_key'] ?? null;
        if ($apiKey === null || $apiKey === '') {
            $env = getenv('TEEPAY_API_KEY');
            $apiKey = is_string($env) && $env !== '' ? $env : null;
        }
        if (!is_string($apiKey)) {
            throw new TeePayError('No TeePay API key. Pass it to new TeePay(...) or set the TEEPAY_API_KEY environment variable.');
        }
        $apiKey = trim($apiKey);
        if (str_starts_with($apiKey, 'tp_live_')) {
            $this->environment = 'live';
        } elseif (str_starts_with($apiKey, 'tp_test_')) {
            $this->environment = 'sandbox';
        } else {
            throw new TeePayError('The TeePay API key must start with tp_test_ (sandbox) or tp_live_ (live).');
        }
        if (preg_match('/\s/', $apiKey)) {
            throw new TeePayError('The TeePay API key contains whitespace.');
        }
        $baseUrl = rtrim((string) ($config['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        if (!preg_match('#^https?://[^/?\#]+(/[^?\#]*)?$#', $baseUrl)) {
            throw new \InvalidArgumentException('base_url must be an http(s) URL without query or fragment.');
        }
        $timeout = (float) ($config['timeout'] ?? self::DEFAULT_TIMEOUT);
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be greater than zero seconds.');
        }
        $maxRetries = (int) ($config['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('max_retries must be zero or more.');
        }
        $httpClient = $config['http_client'] ?? new CurlHttpClient();
        if (!$httpClient instanceof HttpClientInterface) {
            throw new \InvalidArgumentException('http_client must implement TeePay\HttpClient\HttpClientInterface.');
        }
        $sleeper = $config['sleeper'] ?? null;
        if ($sleeper !== null && !is_callable($sleeper)) {
            throw new \InvalidArgumentException('sleeper must be callable.');
        }
        $apiVersion = $config['api_version'] ?? null;

        $this->requestor = new ApiRequestor($apiKey, $baseUrl, $timeout, $maxRetries, $apiVersion, $httpClient, $sleeper);
        $this->collections = new Collections($this->requestor);
        $this->disbursements = new Disbursements($this->requestor);
        $this->controlNumbers = new ControlNumbers($this->requestor);
        $this->checkoutSessions = new CheckoutSessions($this->requestor);
        $this->webhooks = new Webhooks();
    }

    /**
     * Details of the key making the request: application_reference, environment, scopes and
     * credential_prefix. Never returns the secret. GET /v1/whoami (any valid key).
     *
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function whoami(array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/whoami', null, null, null, $opts);
    }

    /**
     * What this key can do right now, plus supported_countries and supported_currencies.
     * GET /v1/capabilities (any valid key). Cache it for a few minutes.
     *
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function capabilities(array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/capabilities', null, null, null, $opts);
    }

    public function isLive(): bool
    {
        return $this->environment === 'live';
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment, 'apiKey' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('The TeePay client holds a secret key and cannot be serialized.');
    }
}
