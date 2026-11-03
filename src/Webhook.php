<?php

declare(strict_types=1);

namespace TeePay;

use TeePay\Exception\WebhookVerificationError;

/**
 * Verifies TeePay webhook requests.
 *
 * TeePay signs every delivery with your webhook secret:
 * X-TeePay-Signature = "v1=" + hex(HMAC-SHA256(secret, "<X-TeePay-Timestamp>." + raw body)).
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;
    public const SIGNATURE_HEADER = 'X-TeePay-Signature';
    public const TIMESTAMP_HEADER = 'X-TeePay-Timestamp';
    public const EVENT_ID_HEADER = 'X-TeePay-Event-ID';

    /**
     * Verifies the signature and age of a webhook request and returns the parsed event.
     *
     * @param string               $payload   the raw request body bytes, before any JSON parsing
     *                                        (file_get_contents('php://input'))
     * @param array<string, mixed> $headers   request headers; any casing, getallheaders(), $_SERVER
     *                                        (HTTP_X_TEEPAY_SIGNATURE) or PSR-7 getHeaders() arrays
     * @param string               $secret    your webhook signing secret (whsec_...)
     * @param int                  $tolerance maximum difference in seconds between now and the timestamp
     * @param int|null             $now       current Unix time, for tests
     *
     * @throws WebhookVerificationError when the request is not a valid, fresh TeePay delivery
     */
    public static function verify(mixed $payload, array $headers, #[\SensitiveParameter] string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): TeePayObject
    {
        if (!is_string($payload)) {
            throw new \InvalidArgumentException('Pass the raw request body string (file_get_contents(\'php://input\')), not decoded JSON. The signature covers the exact bytes TeePay sent.');
        }
        if ($secret === '') {
            throw new \InvalidArgumentException('The webhook secret is empty. Set it from Developers > Webhooks in the TeePay dashboard.');
        }
        $normalized = self::normalizeHeaders($headers);
        $timestamp = $normalized['x-teepay-timestamp'] ?? '';
        $signatures = $normalized['x-teepay-signature'] ?? '';
        if ($timestamp === '' || $signatures === '') {
            throw new WebhookVerificationError('Missing X-TeePay-Timestamp or X-TeePay-Signature header.');
        }
        if (!ctype_digit($timestamp) || strlen($timestamp) > 12) {
            throw new WebhookVerificationError('Invalid X-TeePay-Timestamp header.');
        }
        $now ??= time();
        if (abs($now - (int) $timestamp) > $tolerance) {
            throw new WebhookVerificationError('The webhook timestamp is outside the allowed tolerance.');
        }
        $expected = self::sign($payload, $secret, (int) $timestamp);
        $valid = false;
        foreach (explode(',', $signatures) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'v1=') && hash_equals($expected, $candidate)) {
                $valid = true;
            }
        }
        if (!$valid) {
            throw new WebhookVerificationError('The webhook signature does not match.');
        }
        try {
            $event = json_decode($payload, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookVerificationError('The webhook body is not valid JSON.');
        }
        if (!is_array($event) || array_is_list($event)) {
            throw new WebhookVerificationError('The webhook body is not a JSON object.');
        }

        return new TeePayObject($event);
    }

    /**
     * Returns true when verify() would succeed.
     *
     * @param array<string, mixed> $headers
     */
    public static function isValid(mixed $payload, array $headers, #[\SensitiveParameter] string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        try {
            self::verify($payload, $headers, $secret, $tolerance, $now);

            return true;
        } catch (WebhookVerificationError|\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Computes the X-TeePay-Signature value ("v1=<hex>") for a body and timestamp.
     */
    public static function sign(string $payload, #[\SensitiveParameter] string $secret, int $timestamp): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /**
     * Headers for a signed test delivery, for your own tests.
     *
     * @return array<string, string>
     */
    public static function generateTestHeaders(string $payload, #[\SensitiveParameter] string $secret, ?int $timestamp = null, ?string $eventId = null): array
    {
        $timestamp ??= time();
        $headers = [
            'Content-Type' => 'application/json',
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SIGNATURE_HEADER => self::sign($payload, $secret, $timestamp),
        ];
        if ($eventId !== null) {
            $headers[self::EVENT_ID_HEADER] = $eventId;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $name = strtolower(str_replace('_', '-', (string) $name));
            if (str_starts_with($name, 'http-')) {
                $name = substr($name, 5);
            }
            if (is_array($value)) {
                $value = $value[array_key_first($value)] ?? '';
            }
            if (is_string($value) || is_int($value)) {
                $result[$name] = trim((string) $value);
            }
        }

        return $result;
    }
}
