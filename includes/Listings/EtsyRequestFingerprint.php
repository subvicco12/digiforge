<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Canonical fingerprint boundary for Etsy operation request payloads.
 *
 * The same canonicalization is used when an operation is created and when a
 * prepared payload is verified. This class performs no persistence or external action.
 */
final class EtsyRequestFingerprint
{
    public static function fromPayload(array $payload): string|WP_Error
    {
        $canonical = self::canonicalize($payload);
        $encoded = wp_json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return new WP_Error('digiforge_etsy_request_fingerprint', 'Etsy request payload is not JSON encodable.', ['status'=>400]);
        }
        return hash('sha256', $encoded);
    }

    /** @return array<mixed> */
    private static function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }
        return $value;
    }
}
