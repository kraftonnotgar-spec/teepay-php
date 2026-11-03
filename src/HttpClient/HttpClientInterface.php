<?php

declare(strict_types=1);

namespace TeePay\HttpClient;

use TeePay\Exception\ConnectionError;
use TeePay\Exception\TimeoutError;

/**
 * Transport used by the client. Implement it to plug in your own HTTP stack.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws ConnectionError when no response was received
     * @throws TimeoutError    when the request timed out
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse;
}
