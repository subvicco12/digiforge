<?php

declare(strict_types=1);

namespace DigiForge\Listings;

use InvalidArgumentException;

final class Validator
{
    public const MAX_BODY_BYTES = 65536;

    public static function environment(string $value): string
    {
        if (! in_array($value, ['sandbox','test','production'], true)) {
            throw new InvalidArgumentException('Invalid environment.');
        }
        return $value;
    }

    public static function channel(string $value): string
    {
        if ($value !== 'etsy') {
            throw new InvalidArgumentException('Unsupported channel.');
        }
        return $value;
    }

    public static function title(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 191) {
            throw new InvalidArgumentException('Invalid listing title.');
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

    public static function price(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Invalid price.');
        }
        $price = (float) $value;
        if ($price < 0 || $price > 1000000) {
            throw new InvalidArgumentException('Invalid price.');
        }
        return $price;
    }

    /** @param mixed $value */
    public static function structured($value, int $maxItems = 100): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Structured field must be an array.');
        }
        if (count($value) > $maxItems) {
            throw new InvalidArgumentException('Structured field exceeds item limit.');
        }
        self::rejectCredentials($value);
        $json = wp_json_encode($value);
        if (! is_string($json) || strlen($json) > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('Structured field exceeds size limit.');
        }
        return $value;
    }

    /** @param array<mixed> $value */
    public static function canonicalJson(array $value): string
    {
        $normalize = static function ($item) use (&$normalize) {
            if (! is_array($item)) { return $item; }
            if (array_is_list($item)) { return array_map($normalize, $item); }
            ksort($item, SORT_STRING);
            foreach ($item as $k => $v) { $item[$k] = $normalize($v); }
            return $item;
        };
        return (string) wp_json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param mixed $value */
    public static function rejectCredentials($value): void
    {
        if (! is_array($value)) { return; }
        foreach ($value as $key => $child) {
            if (is_string($key) && preg_match('/(password|secret|token|api[_-]?key|credential|client[_-]?secret)/i', $key)) {
                throw new InvalidArgumentException('Credential-like keys are not permitted.');
            }
            if (is_array($child)) { self::rejectCredentials($child); }
        }
    }
}
