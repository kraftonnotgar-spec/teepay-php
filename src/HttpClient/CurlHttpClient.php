<?php

declare(strict_types=1);

namespace TeePay\HttpClient;

use TeePay\Exception\ConnectionError;
use TeePay\Exception\TimeoutError;

/**
 * Default transport built on ext-curl. TLS certificates are always verified.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new ConnectionError('Could not initialise the HTTP client.');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        // Stop curl from adding "Expect: 100-continue" on larger bodies.
        $lines[] = 'Expect:';
        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => (int) max(1, min($timeout, 10.0) * 1000),
            CURLOPT_TIMEOUT_MS => (int) max(1, $timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    // A new status line (e.g. after 100 Continue) starts a fresh header set.
                    $responseHeaders = [];
                }

                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $result = curl_exec($handle);
        if ($result === false) {
            $errno = curl_errno($handle);
            $message = curl_error($handle);
            curl_close($handle);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutError('The request to TeePay timed out. Retry with the same idempotency key.');
            }

            throw new ConnectionError('Could not connect to TeePay: ' . $message);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, $responseHeaders, (string) $result);
    }
}
