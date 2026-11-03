<?php

declare(strict_types=1);

namespace TeePay;

/**
 * Request helpers. Not part of the public API.
 *
 * @internal
 */
final class Util
{
    /** Fields the API expects as JSON objects even when empty. */
    private const OBJECT_FIELDS = ['money', 'customer', 'destination', 'metadata'];

    /**
     * Generates an idempotency key such as "sdk-1b4e28ba-2fa1-4d2a-9f3e-5c6d7e8f9a0b".
     */
    public static function idempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return 'sdk-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /**
     * Drops null fields, turns empty object fields into JSON objects and enforces the API's
     * rule that every JSON object key is lower-case (metadata keys included).
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    public static function prepareBody(array $value, string $path = ''): array
    {
        $result = [];
        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            if ($item === null) {
                continue;
            }
            if (!$list) {
                $key = (string) $key;
                if ($key !== strtolower($key)) {
                    throw new \InvalidArgumentException(sprintf('TeePay rejects JSON keys that are not lower-case: "%s%s". Use lower-case keys (metadata keys included).', $path, $key));
                }
            }
            if (is_array($item)) {
                if ($item === [] && in_array($key, self::OBJECT_FIELDS, true)) {
                    $item = new \stdClass();
                } else {
                    $item = self::prepareBody($item, $path . $key . '.');
                }
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * Converts an integer amount to the decimal string the API expects for "*_minor" request fields.
     */
    public static function minor(mixed $value, string $field): mixed
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new \InvalidArgumentException(sprintf('%s must be a positive integer number of minor units.', $field));
            }

            return (string) $value;
        }
        if (is_float($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be an integer or a string of digits in minor units, not a float.', $field));
        }

        return $value;
    }

    public static function pathParam(mixed $value, string $name): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf('%s must be a non-empty string.', $name));
        }

        return rawurlencode($value);
    }
}
