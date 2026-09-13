<?php

declare(strict_types=1);

namespace DigiForge\Finance;

use InvalidArgumentException;

final class Validator
{
    public const MAX_BODY_BYTES = 65536;

    private const ENTRY_TYPES = [
        'REVENUE', 'COGS', 'PLATFORM_FEE', 'PAYMENT_FEE', 'SHIPPING_COST',
        'TAX_COLLECTED', 'TAX_EXPENSE', 'REFUND_RESERVE', 'AD_SPEND',
        'OTHER_INCOME', 'OTHER_EXPENSE', 'ADJUSTMENT',
    ];

    private const FORBIDDEN_KEYS = [
        'api_key', 'apikey', 'secret', 'password', 'access_token', 'refresh_token',
        'client_secret', 'private_key', 'authorization', 'bearer', 'credential', 'credentials',
        'bank_account', 'card_number', 'cvv', 'routing_number',
    ];

    public static function environment(string $value): string
    {
        $value = strtolower(trim($value));
        if (! in_array($value, ['sandbox', 'test', 'production'], true)) {
            throw new InvalidArgumentException('Invalid environment.');
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
        if ($amount < -9999999999.9999 || $amount > 9999999999.9999) {
            throw new InvalidArgumentException('Amount outside allowed bounds.');
        }
        return $amount;
    }

    public static function positiveRate(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Invalid FX rate.');
        }
        $rate = round((float) $value, 10);
        if ($rate <= 0 || $rate > 999999999.9999999999) {
            throw new InvalidArgumentException('FX rate outside allowed bounds.');
        }
        return $rate;
    }

    public static function entryType(string $value): string
    {
        $value = strtoupper(trim($value));
        if (! in_array($value, self::ENTRY_TYPES, true)) {
            throw new InvalidArgumentException('Invalid finance entry type.');
        }
        return $value;
    }

    public static function date(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Invalid date.');
        }
        return $date->format('Y-m-d');
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
        if (strlen(self::canonicalJson($value)) > self::MAX_BODY_BYTES) {
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

    public static function convert(float $amount, float $rate): float
    {
        if ($rate <= 0) {
            throw new InvalidArgumentException('FX rate must be positive.');
        }
        return round($amount * $rate, 4);
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
        if (! array_is_list($value)) {
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
