<?php

declare(strict_types=1);

namespace DigiForge\Orders;

use InvalidArgumentException;

final class Validator
{
    private const MAX_STRUCTURED_BYTES = 65536;
    private const FORBIDDEN_KEYS = [
        'api_key', 'apikey', 'secret', 'password', 'access_token', 'refresh_token',
        'client_secret', 'private_key', 'authorization', 'bearer', 'credential', 'credentials',
    ];

    public static function environment(string $value): string
    {
        $value = strtolower(trim($value));
        if (! in_array($value, ['sandbox', 'test', 'production'], true)) {
            throw new InvalidArgumentException('Invalid environment.');
        }
        return $value;
    }

    public static function channel(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value !== 'etsy') {
            throw new InvalidArgumentException('Unsupported order channel.');
        }
        return $value;
    }

    public static function currency(string $value): string
    {
        $value = strtoupper(trim($value));
        if (! preg_match('/^[A-Z]{3}$/', $value)) {
            throw new InvalidArgumentException('Invalid currency.');
        }
        return $value;
    }

    public static function amount(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Invalid amount.');
        }
        $amount = round((float) $value, 4);
        if ($amount < 0 || $amount > 99999999.9999) {
            throw new InvalidArgumentException('Amount outside allowed bounds.');
        }
        return $amount;
    }

    public static function quantity(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Invalid quantity.');
        }
        $quantity = (int) $value;
        if ($quantity < 1 || $quantity > 1000) {
            throw new InvalidArgumentException('Quantity outside allowed bounds.');
        }
        return $quantity;
    }

    public static function structured(mixed $value): array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Structured payload must be an object or array.');
        }
        self::rejectCredentialKeys($value);
        $encoded = self::canonicalJson($value);
        if (strlen($encoded) > self::MAX_STRUCTURED_BYTES) {
            throw new InvalidArgumentException('Structured payload exceeds 64 KiB.');
        }
        return $value;
    }

    public static function canonicalJson(array $value): string
    {
        $normalized = self::canonicalize($value);
        $json = wp_json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new InvalidArgumentException('Payload cannot be encoded.');
        }
        return $json;
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    private static function rejectCredentialKeys(array $value): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized = strtolower(str_replace(['-', ' '], '_', $key));
                if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
                    throw new InvalidArgumentException('Credential-like keys are forbidden.');
                }
            }
            if (is_object($item)) {
                $item = get_object_vars($item);
            }
            if (is_array($item)) {
                self::rejectCredentialKeys($item);
            }
        }
    }

    private static function canonicalize(array $value): array
    {
        $isList = array_is_list($value);
        if (! $isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_object($item)) {
                $item = get_object_vars($item);
            }
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            } elseif (is_string($item)) {
                $value[$key] = trim($item);
            }
        }
        return $value;
    }
}
