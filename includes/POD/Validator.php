<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Production\Validator as ProductionValidator;
use WP_Error;

final class Validator
{
    private ProductionValidator $structured;

    public function __construct()
    {
        $this->structured = new ProductionValidator();
    }

    public function provider(string $provider): bool
    {
        return in_array($provider, ['printify','gelato','future_provider'], true);
    }

    public function environment(string $environment): bool
    {
        return in_array($environment, ['sandbox','test','production'], true);
    }

    public function identifier(string $value, int $max = 191): bool
    {
        return $value !== '' && strlen($value) <= $max && (bool) preg_match('/^[A-Za-z0-9._:\/-]+$/', $value);
    }

    /** @param mixed $value */
    public function structured($value)
    {
        return $this->structured->boundedStructured($value);
    }

    /** @param array<mixed> $value */
    public function canonicalJson(array $value): string
    {
        return $this->structured->canonicalJson($value);
    }

    public function printDimensions(float $width, float $height, int $dpi, string $unit): ?WP_Error
    {
        if ($width <= 0 || $height <= 0 || $width > 100000 || $height > 100000 || $dpi < 0 || $dpi > 2400) {
            return new WP_Error('invalid_print_dimensions', 'Print-area dimensions are outside allowed bounds.', ['status' => 400]);
        }
        if (! in_array($unit, ['px','in','mm','cm'], true)) {
            return new WP_Error('invalid_print_unit', 'Unsupported print-area unit.', ['status' => 400]);
        }
        return null;
    }

    /** @param array<mixed> $definitions */
    public function personalizationFields(array $definitions)
    {
        $bounded = $this->structured($definitions);
        if ($bounded instanceof WP_Error) {
            return $bounded;
        }
        if (count($definitions) > 100) {
            return new WP_Error('too_many_personalization_fields', 'Too many personalization fields.', ['status' => 400]);
        }
        $seen = [];
        foreach ($definitions as $field) {
            if (! is_array($field)) {
                return new WP_Error('invalid_personalization_field', 'Each personalization field must be an object.', ['status' => 400]);
            }
            $key = isset($field['key']) && is_string($field['key']) ? $field['key'] : '';
            $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : '';
            if (! $this->identifier($key, 100) || isset($seen[$key])) {
                return new WP_Error('invalid_personalization_field_key', 'Personalization field keys must be unique identifiers.', ['status' => 400]);
            }
            if (! in_array($type, ['text','choice','date','number','image_reference'], true)) {
                return new WP_Error('invalid_personalization_field_type', 'Unsupported personalization field type.', ['status' => 400]);
            }
            $minLength = isset($field['min_length']) ? (int) $field['min_length'] : 0;
            $maxLength = isset($field['max_length']) ? (int) $field['max_length'] : 0;
            if ($minLength < 0 || $maxLength < 0 || $minLength > 10000 || $maxLength > 10000 || ($maxLength > 0 && $minLength > $maxLength)) {
                return new WP_Error('invalid_personalization_bounds', 'Personalization field bounds are invalid.', ['status' => 400]);
            }
            $seen[$key] = true;
        }
        return $definitions;
    }

    public function nonNegativeMoney(float $amount): bool
    {
        return is_finite($amount) && $amount >= 0 && $amount <= 1000000;
    }
}
