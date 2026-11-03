<?php

declare(strict_types=1);

namespace TeePay;

use TeePay\HttpClient\HttpResponse;

/**
 * Metadata about the HTTP response a result came from.
 */
final class ResponseInfo
{
    /**
     * @param array<string, string>                                   $headers   lower-case names
     * @param array{limit: int, remaining: int, reset: int}|null $rateLimit
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly ?string $requestId,
        public readonly bool $idempotentReplayed,
        public readonly ?array $rateLimit,
        public readonly ?string $apiVersion,
    ) {
    }

    public static function fromResponse(HttpResponse $response): self
    {
        $rateLimit = null;
        $limit = $response->header('ratelimit-limit');
        $remaining = $response->header('ratelimit-remaining');
        $reset = $response->header('ratelimit-reset');
        if ($limit !== null && $remaining !== null && $reset !== null
            && ctype_digit($limit) && ctype_digit($remaining) && ctype_digit($reset)) {
            $rateLimit = ['limit' => (int) $limit, 'remaining' => (int) $remaining, 'reset' => (int) $reset];
        }

        return new self(
            $response->status,
            $response->headers,
            $response->header('x-request-id'),
            strtolower((string) $response->header('idempotent-replayed')) === 'true',
            $rateLimit,
            $response->header('teepay-version'),
        );
    }
}
