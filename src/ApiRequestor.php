<?php

declare(strict_types=1);

namespace TeePay;

use TeePay\Exception\AccountRestrictedError;
use TeePay\Exception\ApiError;
use TeePay\Exception\AuthenticationError;
use TeePay\Exception\ConflictError;
use TeePay\Exception\ConnectionError;
use TeePay\Exception\NotFoundError;
use TeePay\Exception\PermissionError;
use TeePay\Exception\RateLimitError;
use TeePay\Exception\ValidationError;
use TeePay\HttpClient\HttpClientInterface;
use TeePay\HttpClient\HttpResponse;

/**
 * Sends requests, applies retries and maps errors. Not part of the public API.
 *
 * @internal
 */
final class ApiRequestor
{
    private const RETRY_STATUSES = [408, 429, 500, 502, 503, 504];
    private const MAX_RETRY_AFTER = 60;

    /** @var callable(float): void */
    private $sleeper;

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly ?string $apiVersion,
        private readonly HttpClientInterface $httpClient,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /**
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     * @param array{idempotency_key?: string, timeout?: float|int, max_retries?: int} $opts
     */
    public function request(string $method, string $path, ?array $query = null, ?array $body = null, ?string $idempotencyKey = null, array $opts = [], bool $bodyKeyed = false): TeePayObject
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            $query = array_filter($query, static fn ($value) => $value !== null);
            if ($query !== []) {
                $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }
        }
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'teepay-php/' . TeePay::VERSION . ' php/' . PHP_VERSION,
        ];
        if ($this->apiVersion !== null) {
            $headers['TeePay-Version'] = $this->apiVersion;
        }
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $timeout = (float) ($opts['timeout'] ?? $this->timeout);
        $maxRetries = (int) ($opts['max_retries'] ?? $this->maxRetries);
        // Repeating is safe for reads and for creates that carry an idempotency key.
        $safe = $method === 'GET' || $idempotencyKey !== null || $bodyKeyed;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->httpClient->send($method, $url, $headers, $payload, $timeout);
            } catch (ConnectionError $error) {
                if ($safe && $attempt < $maxRetries) {
                    ($this->sleeper)($this->backoff($attempt));
                    continue;
                }
                throw $error;
            }
            if ($response->status >= 200 && $response->status < 300) {
                return $this->decode($response);
            }
            $retryable = in_array($response->status, self::RETRY_STATUSES, true) && ($safe || $response->status === 429);
            $retryAfter = self::retryAfter($response);
            if ($retryable && $attempt < $maxRetries && ($retryAfter === null || $retryAfter <= self::MAX_RETRY_AFTER)) {
                ($this->sleeper)($retryAfter ?? $this->backoff($attempt));
                continue;
            }
            throw self::errorFromResponse($response);
        }
    }

    private function backoff(int $attempt): float
    {
        $cap = min(8.0, 0.5 * (2 ** $attempt));

        return $cap * (random_int(0, 1000) / 1000);
    }

    private function decode(HttpResponse $response): TeePayObject
    {
        $info = ResponseInfo::fromResponse($response);
        if (trim($response->body) === '') {
            return new TeePayObject([], $info);
        }
        try {
            $data = json_decode($response->body, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError('TeePay returned a response that is not valid JSON.', $response->status, null, $info->requestId, $response->headers, $response->body);
        }
        if (!is_array($data)) {
            throw new ApiError('TeePay returned an unexpected response.', $response->status, null, $info->requestId, $response->headers, $data);
        }

        return new TeePayObject($data, $info);
    }

    private static function retryAfter(HttpResponse $response): ?int
    {
        $value = $response->header('retry-after');

        return $value !== null && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }

    /**
     * Builds the typed exception for an error response. Accepts every error shape the API uses:
     * {"error":{"code","message","request_id"}}, {"error":{"code"}}, {"error":"code"} and non-JSON.
     */
    public static function errorFromResponse(HttpResponse $response): ApiError
    {
        $body = json_decode($response->body, true);
        $code = null;
        $message = null;
        $requestId = null;
        if (is_array($body) && isset($body['error'])) {
            if (is_array($body['error'])) {
                $code = is_string($body['error']['code'] ?? null) ? $body['error']['code'] : null;
                $message = is_string($body['error']['message'] ?? null) && $body['error']['message'] !== '' ? $body['error']['message'] : null;
                $requestId = is_string($body['error']['request_id'] ?? null) && $body['error']['request_id'] !== '' ? $body['error']['request_id'] : null;
            } elseif (is_string($body['error'])) {
                $code = $body['error'];
            }
        }
        $requestId ??= $response->header('x-request-id');
        $status = $response->status;
        $message ??= self::defaultMessage($status, $code);
        $retryAfter = self::retryAfter($response);
        $class = match (true) {
            $status === 401 => AuthenticationError::class,
            $status === 403 && $code === 'account_restricted' => AccountRestrictedError::class,
            $status === 403 => PermissionError::class,
            $status === 404 => NotFoundError::class,
            $status === 409 => ConflictError::class,
            $status === 400, $status === 422 => ValidationError::class,
            $status === 429 => RateLimitError::class,
            default => ApiError::class,
        };

        return new $class($message, $status, $code, $requestId, $response->headers, $body ?? $response->body, $retryAfter);
    }

    private static function defaultMessage(int $status, ?string $code): string
    {
        $text = match (true) {
            $status === 401 => 'A valid API key is required.',
            $status === 403 => 'This API key or account is not allowed to perform this action.',
            $status === 404 => 'The requested resource could not be found.',
            $status === 409 => 'The request conflicts with an earlier request.',
            $status === 400, $status === 422 => 'The request is invalid.',
            $status === 429 => 'Too many requests. Wait before trying again.',
            $status >= 500 => 'TeePay is temporarily unavailable. Retry with the same idempotency key.',
            default => 'The TeePay API returned an error.',
        };

        return $code !== null ? $text . ' (' . $code . ')' : $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '[redacted]'];
    }
}
