<?php

declare(strict_types=1);

namespace DigiForge\POD;

/**
 * Provider-neutral normalization contract for future Printify catalog sync.
 *
 * This class deliberately performs no HTTP requests. It validates and normalizes
 * already-fetched provider data so the existing POD repository can persist
 * evidence without enabling provider execution, publishing, or fulfillment.
 */
final class PrintifyCatalogContract
{
    public const PROVIDER = 'printify';

    /** @return array<string,mixed> */
    public static function normalizeCatalogVariant(array $input): array
    {
        $blueprintId = self::positiveInt($input['blueprint_id'] ?? null, 'blueprint_id');
        $providerId = self::positiveInt($input['print_provider_id'] ?? null, 'print_provider_id');
        $variantId = self::positiveInt($input['variant_id'] ?? null, 'variant_id');
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'USD')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('currency must be a three-letter ISO-style code');
        }

        return [
            'provider' => self::PROVIDER,
            'environment' => self::environment($input['environment'] ?? 'production'),
            'provider_product_key' => (string) $blueprintId,
            'provider_variant_key' => $providerId . ':' . $variantId,
            'title' => sanitize_text_field((string) ($input['title'] ?? '')),
            'variant_label' => sanitize_text_field((string) ($input['variant_label'] ?? '')),
            'attributes' => self::cleanStructured($input['attributes'] ?? []),
            'currency' => $currency,
            'base_cost' => self::money($input['base_cost'] ?? 0),
            'shipping_profile' => self::cleanStructured($input['shipping_profile'] ?? []),
            'availability_state' => self::availability($input['availability_state'] ?? 'UNKNOWN'),
            'source_revision' => sanitize_text_field((string) ($input['source_revision'] ?? '')),
            'observed_at' => self::dateTime($input['observed_at'] ?? null),
            'state' => 'DRAFT',
        ];
    }

    /** @return array<string,mixed> */
    public static function normalizePrintArea(array $input): array
    {
        $position = self::token($input['position'] ?? '', 'position');
        $method = self::token($input['decoration_method'] ?? '', 'decoration_method');
        $width = self::positiveNumber($input['width_px'] ?? null, 'width_px');
        $height = self::positiveNumber($input['height_px'] ?? null, 'height_px');

        return [
            'area_key' => $position . ':' . $method,
            'placement' => $position,
            'width_value' => $width,
            'height_value' => $height,
            'unit' => 'px',
            'dpi_target' => max(0, (int) ($input['dpi_target'] ?? 0)),
            'bleed_metadata' => self::cleanStructured($input['bleed_metadata'] ?? []),
            'safe_area_metadata' => self::cleanStructured($input['safe_area_metadata'] ?? []),
            'accepted_formats' => self::cleanStructured($input['accepted_formats'] ?? []),
            'background_policy' => sanitize_key((string) ($input['background_policy'] ?? '')),
            'orientation_policy' => sanitize_key((string) ($input['orientation_policy'] ?? '')),
            'decoration_method' => $method,
        ];
    }

    /** @return array<string,mixed> */
    public static function templateFingerprint(array $variant, array $areas): array
    {
        $normalizedAreas = [];
        foreach ($areas as $area) {
            if (!is_array($area)) {
                throw new \InvalidArgumentException('print areas must be arrays');
            }
            $normalizedAreas[] = self::normalizePrintArea($area);
        }
        usort($normalizedAreas, static fn(array $a, array $b): int => strcmp((string) $a['area_key'], (string) $b['area_key']));
        $catalog = self::normalizeCatalogVariant($variant);
        $payload = [
            'provider' => $catalog['provider'],
            'environment' => $catalog['environment'],
            'product' => $catalog['provider_product_key'],
            'variant' => $catalog['provider_variant_key'],
            'areas' => $normalizedAreas,
        ];

        return [
            'fingerprint' => hash('sha256', wp_json_encode($payload, JSON_UNESCAPED_SLASHES) ?: ''),
            'catalog' => $catalog,
            'areas' => $normalizedAreas,
        ];
    }

    private static function environment(mixed $value): string
    {
        $value = sanitize_key((string) $value);
        if (!in_array($value, ['sandbox', 'test', 'production'], true)) {
            throw new \InvalidArgumentException('unsupported environment');
        }
        return $value;
    }

    private static function availability(mixed $value): string
    {
        $value = strtoupper(sanitize_key((string) $value));
        $allowed = ['UNKNOWN', 'AVAILABLE', 'UNAVAILABLE', 'DISCONTINUED'];
        return in_array($value, $allowed, true) ? $value : 'UNKNOWN';
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            throw new \InvalidArgumentException($field . ' must be a positive integer');
        }
        return (int) $value;
    }

    private static function positiveNumber(mixed $value, string $field): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new \InvalidArgumentException($field . ' must be positive');
        }
        return round((float) $value, 4);
    }

    private static function money(mixed $value): float
    {
        if (!is_numeric($value) || (float) $value < 0) {
            throw new \InvalidArgumentException('base_cost must be non-negative');
        }
        return round((float) $value, 4);
    }

    private static function token(mixed $value, string $field): string
    {
        $value = sanitize_key((string) $value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' is required');
        }
        return $value;
    }

    private static function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('observed_at is invalid');
        }
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function cleanStructured(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[is_string($key) ? sanitize_key($key) : $key] = self::cleanStructured($item);
            }
            return $clean;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        return sanitize_text_field((string) $value);
    }
}
