<?php

declare(strict_types=1);

namespace TeePay\Service;

use TeePay\TeePayObject;
use TeePay\Webhook;

/**
 * Webhook signature verification. Same as the static {@see Webhook} helpers.
 */
final class Webhooks
{
    /**
     * @param string               $payload   the raw request body, e.g. file_get_contents('php://input')
     * @param array<string, mixed> $headers   request headers (getallheaders(), $_SERVER or PSR-7 getHeaders())
     * @param string               $secret    your webhook signing secret (whsec_...)
     * @param int                  $tolerance maximum age of the signature in seconds
     */
    public function verify(mixed $payload, array $headers, #[\SensitiveParameter] string $secret, int $tolerance = Webhook::DEFAULT_TOLERANCE): TeePayObject
    {
        return Webhook::verify($payload, $headers, $secret, $tolerance);
    }

    public function sign(string $payload, #[\SensitiveParameter] string $secret, int $timestamp): string
    {
        return Webhook::sign($payload, $secret, $timestamp);
    }

    /**
     * @return array<string, string>
     */
    public function generateTestHeaders(string $payload, #[\SensitiveParameter] string $secret, ?int $timestamp = null, ?string $eventId = null): array
    {
        return Webhook::generateTestHeaders($payload, $secret, $timestamp, $eventId);
    }
}
