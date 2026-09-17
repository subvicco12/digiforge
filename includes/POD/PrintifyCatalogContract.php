<?php

declare(strict_types=1);

namespace DigiForge\POD;

/**
 * Fail-closed normalization boundary for future Printify catalog sync.
 * Shared supplier/catalog evidence is intentionally business-neutral; business
 * and store ownership is applied only when a DigiForge program maps to it.
 */
final class PrintifyCatalogContract
{
    public const PROVIDER = 'printify';
    public const PROGRAM_PERSONALIZED_POD = 'PERSONALIZED_POD';
    public const PROGRAM_ORIGINAL_DESIGN_POD = 'ORIGINAL_DESIGN_POD';

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
    public static function normalizeBusinessScope(array $input): array
    {
        $businessId = self::token($input['business_id'] ?? '', 'business_id');
        $storeId = self::token($input['store_id'] ?? '', 'store_id');
        $program = strtoupper(trim((string) ($input['product_program'] ?? '')));
        if (!in_array($program, [self::PROGRAM_PERSONALIZED_POD, self::PROGRAM_ORIGINAL_DESIGN_POD], true)) {
            throw new \InvalidArgumentException('unsupported product_program');
        }
        if ($businessId === 'digicraftifygoods' && $program !== self::PROGRAM_PERSONALIZED_POD) {
            throw new \InvalidArgumentException('DigiCraftifyGoods is restricted to PERSONALIZED_POD');
        }

        return [
            'business_id' => $businessId,
            'store_id' => $storeId,
            'product_program' => $program,
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
            // Use a sanitize_key-safe delimiter so Repository::createPrintArea()
            // preserves the position/decoration boundary during persistence.
            'area_key' => $position . '-' . $method,
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
        if ($normalizedAreas === []) {
            throw new \InvalidArgumentException('at least one print area is required');
        }
        usort($normalizedAreas, static fn(array $a, array $b): int => strcmp((string) $a['area_key'], (string) $b['area_key']));
        $catalog = self::normalizeCatalogVariant($variant);
        $payload = self::canonicalize([
            'provider' => $catalog['provider'],
            'environment' => $catalog['environment'],
            'product' => $catalog['provider_product_key'],
            'variant' => $catalog['provider_variant_key'],
            'areas' => $normalizedAreas,
        ]);
        $encoded = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \InvalidArgumentException('template fingerprint payload is not JSON encodable');
        }

        return [
            'fingerprint' => hash('sha256', $encoded),
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
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($field . ' must be positive');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number <= 0) {
            throw new \InvalidArgumentException($field . ' must be finite and positive');
        }
        return round($number, 4);
    }

    private static function money(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('base_cost must be non-negative');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0) {
            throw new \InvalidArgumentException('base_cost must be finite and non-negative');
        }
        return round($number, 4);
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
        $raw = trim((string) $value);
        // Provider evidence must be absolute and round-trippable. Relative strings
        // such as "tomorrow" are deliberately rejected.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $raw);
        $format = 'Y-m-d\TH:i:sP';
        if (!$date || $date->format($format) !== $raw) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:sP', $raw);
            $format = 'Y-m-d H:i:sP';
        }
        if (!$date || $date->format($format) !== $raw) {
            throw new \InvalidArgumentException('observed_at must be an absolute ISO-style timestamp with timezone');
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
        if (is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('structured metadata contains a non-finite number');
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        return sanitize_text_field((string) $value);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }
        return $value;
    }
}
