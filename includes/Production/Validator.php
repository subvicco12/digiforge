<?php

declare(strict_types=1);

namespace DigiForge\Production;

use DigiForge\Security\Logger;
use WP_Error;

final class Validator
{
    /** @param mixed $value */
    public function boundedStructured($value, int $maxDepth = 8, int $maxItems = 200, int $maxJsonBytes = 65536)
    {
        if (! is_array($value)) {
            return new WP_Error('invalid_structured_payload', 'Structured payload must be an object or array.', ['status' => 400]);
        }
        $count = 0;
        $error = $this->walk($value, 0, $maxDepth, $maxItems, $count);
        if ($error instanceof WP_Error) {
            return $error;
        }
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json) || strlen($json) > $maxJsonBytes) {
            return new WP_Error('payload_too_large', 'Structured payload exceeds allowed size.', ['status' => 400]);
        }
        return $value;
    }

    /** @param mixed $value */
    private function walk($value, int $depth, int $maxDepth, int $maxItems, int &$count): ?WP_Error
    {
        if ($depth > $maxDepth) {
            return new WP_Error('payload_too_deep', 'Structured payload exceeds allowed nesting.', ['status' => 400]);
        }
        if (! is_array($value)) {
            if (is_string($value) && strlen($value) > 4096) {
                return new WP_Error('payload_value_too_long', 'Structured payload contains an oversized value.', ['status' => 400]);
            }
            return null;
        }
        foreach ($value as $key => $child) {
            $count++;
            if ($count > $maxItems) {
                return new WP_Error('payload_too_many_items', 'Structured payload contains too many items.', ['status' => 400]);
            }
            if (is_string($key) && Logger::isCredentialKey($key)) {
                return new WP_Error('credential_field_rejected', 'Credential-shaped fields are not allowed.', ['status' => 400]);
            }
            $error = $this->walk($child, $depth + 1, $maxDepth, $maxItems, $count);
            if ($error instanceof WP_Error) {
                return $error;
            }
        }
        return null;
    }

    public function dimensions(int $width, int $height, int $dpi): ?WP_Error
    {
        if ($width < 0 || $height < 0 || $dpi < 0 || $width > 50000 || $height > 50000 || $dpi > 2400) {
            return new WP_Error('invalid_dimensions', 'Dimensions or DPI are outside allowed bounds.', ['status' => 400]);
        }
        return null;
    }

    public function checksum(string $checksum): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', strtolower($checksum));
    }

    public function storageReference(string $reference): bool
    {
        if ($reference === '' || strlen($reference) > 255) {
            return false;
        }
        $lower = strtolower($reference);
        if (str_contains($lower, 'http://') || str_contains($lower, 'https://') || str_contains($lower, 'token=') || str_contains($lower, 'signature=') || str_contains($lower, 'x-amz-')) {
            return false;
        }
        return ! Logger::isCredentialKey($reference);
    }

    /** @param array<mixed> $value */
    public function canonicalJson(array $value): string
    {
        $normalized = $this->sortRecursive($value);
        return (string) wp_json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param mixed $value @return mixed */
    private function sortRecursive($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (! $isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursive($child);
        }
        return $value;
    }
}
