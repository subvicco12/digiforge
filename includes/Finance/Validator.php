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
        return (float) ExactDecimal::money(self::decimalInput($value, 4));
    }

    public static function positiveRate(mixed $value): float
    {
        return (float) ExactDecimal::rate(self::decimalInput($value, 10));
    }

    /** Legacy numeric callers remain supported; source strings never undergo rounding. */
    private static function decimalInput(mixed $value, int $scale): string
    {
        if (is_string($value)) return $value;
        if (!is_int($value) && !is_float($value)) throw new InvalidArgumentException('Explicit decimal required.');
        if (!is_finite((float)$value)) throw new InvalidArgumentException('Finite decimal required.');
        $text=(string)$value;
        if (stripos($text, 'e') !== false) {
            $text=number_format((float)$value,$scale,'.','');
            if ((float)$text !== (float)$value) throw new InvalidArgumentException('Unsupported decimal precision.');
        }
        return $text;
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

    /** Full SHA-256 source identity, encoded within the existing 64-character version field. */
    public static function periodSourceVersion(array $source): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $digest = hash('sha256', self::canonicalJson($source), true);
        $encoded = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($digest) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= $alphabet[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
        }
        return 'v2.' . $encoded;
    }

    public static function convert(float $amount, float $rate): float
    {
        // Reuse the existing versioned decimal conversion convention, not binary multiplication.
        return (float) ExactDecimal::convert(
            ExactDecimal::money(self::decimalInput($amount,4)),
            ExactDecimal::rate(self::decimalInput($rate,10))
        );
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
