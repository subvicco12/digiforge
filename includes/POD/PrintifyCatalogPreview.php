<?php

declare(strict_types=1);

namespace DigiForge\POD;

use WP_Error;

/**
 * Non-persisting, provider-specific Printify catalog preview.
 *
 * This is deliberately NOT an HTTP route or an activation path. The caller
 * must separately enforce the existing integration, STOP ALL, external lock,
 * credential-vault and scoped authorization policies before invoking it.
 * No credentials are accepted here; the caller supplies an already authorized
 * read-only catalog client callback.
 */
final class PrintifyCatalogPreview
{
    /**
     * @param callable(int,int):array|WP_Error $variantsRead
     * @param callable(int,int):array|WP_Error $shippingRead
     * @param null|callable(int,int):bool|WP_Error $authorizeRead Explicit caller-owned preflight; null denies all reads.
     * @return array|WP_Error
     */
    public static function inspect(int $blueprintId, int $providerId, callable $variantsRead, callable $shippingRead, ?callable $authorizeRead = null): array|WP_Error
    {
        if ($blueprintId < 1 || $providerId < 1) {
            return new WP_Error('digiforge_printify_preview_identity', 'Positive blueprint and provider IDs required.', ['status' => 400]);
        }

        // Fail closed before either callback can perform network I/O.
        // An actual caller must bind this to authoritative integration and safety checks.
        if ($authorizeRead === null) {
            return new WP_Error('digiforge_printify_preview_authorization', 'Catalog preview authorization is required.', ['status' => 403]);
        }
        $authorized = $authorizeRead($blueprintId, $providerId);
        if (is_wp_error($authorized)) {
            return $authorized;
        }
        if ($authorized !== true) {
            return new WP_Error('digiforge_printify_preview_authorization', 'Catalog preview read not authorized.', ['status' => 403]);
        }

        $response = $variantsRead($blueprintId, $providerId);
        if (is_wp_error($response)) {
            return $response;
        }
        if (!is_array($response) || !isset($response['variants']) || !is_array($response['variants'])) {
            return new WP_Error('digiforge_printify_preview_shape', 'Variant response shape unsupported.', ['status' => 502]);
        }

        $variants = [];
        foreach ($response['variants'] as $variant) {
            if (!is_array($variant) || !isset($variant['id']) || !is_int($variant['id']) || $variant['id'] < 1) {
                return new WP_Error('digiforge_printify_preview_variant', 'Invalid variant identity.', ['status' => 502]);
            }
            $variants[] = [
                'variant_id' => $variant['id'],
                'title' => is_string($variant['title'] ?? null) ? $variant['title'] : '',
                'is_available' => is_bool($variant['is_available'] ?? null) ? $variant['is_available'] : null,
                'print_area' => null, // Never infer geometry from product dimensions.
                'geometry_status' => 'UNKNOWN',
            ];
        }

        $shipping = $shippingRead($blueprintId, $providerId);
        if (is_wp_error($shipping)) {
            return $shipping;
        }
        if (!is_array($shipping)) {
            return new WP_Error('digiforge_printify_preview_shipping', 'Shipping response shape unsupported.', ['status' => 502]);
        }

        // Shipping response is intentionally not echoed: provider-specific
        // country/rate mapping must be validated before public presentation.
        return [
            'blueprint_id' => $blueprintId,
            'print_provider_id' => $providerId,
            'variants' => $variants,
            'shipping_status' => 'RETRIEVED_UNNORMALIZED',
            'shipping_country_rates' => null,
            'persistence' => 'NONE',
            'certification' => 'NOT_CERTIFIED',
        ];
    }
}
