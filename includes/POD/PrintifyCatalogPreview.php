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
 * inspect() is a denied legacy callback path. inspectScoped() uses the existing
 * grant repository. No catalog/artwork persistence or certification occurs.
 */
final class PrintifyCatalogPreview
{
    /** Deprecated callback-only signature retained as a deny-only compatibility boundary. */
    public static function inspect(int $blueprintId, int $providerId, callable $variantsRead, callable $shippingRead): WP_Error
    {
        return PrintifyCatalogReadPolicy::authorize($blueprintId, $providerId);
    }

    /** Only the existing concrete grant repository may source a preview. No supplier callbacks. */
    public static function inspectScoped(string $grantSha, int $index, string $requestKey, ?PrintifyCatalogClient $client = null): array|WP_Error
    {
        $evidence = (new PrintifyCatalogReadRepository($client))->preview($grantSha, $index, $requestKey);
        if (is_wp_error($evidence)) return $evidence;
        $request = $evidence['request'];
        if (($request['kind'] ?? '') !== 'variants') return new WP_Error('digiforge_printify_preview_scope', 'Exact provider variants scope required.', ['status'=>403]);
        $response = $evidence['data'];
        if (!isset($response['variants']) || !is_array($response['variants']) || !array_is_list($response['variants']) || count($response['variants']) > 1000) return new WP_Error('digiforge_printify_preview_shape', 'Bounded variant list required.', ['status'=>502]);
        $blueprintId = $request['blueprint_id']; $providerId = $request['provider_id'];
        if (array_key_exists('id', $response) && $response['id'] !== $providerId) return new WP_Error('digiforge_printify_preview_scope', 'Variant provider identity mismatch.', ['status'=>502]);
        $variants = []; $seen = [];

        foreach ($response['variants'] as $variant) {
            if (!is_array($variant) || !isset($variant['id']) || !is_int($variant['id']) || $variant['id'] < 1) {
                return new WP_Error('digiforge_printify_preview_variant', 'Invalid variant identity.', ['status' => 502]);
            }
            if (isset($seen[$variant['id']])) return new WP_Error('digiforge_printify_preview_variant', 'Ambiguous variant identity.', ['status'=>502]);
            $seen[$variant['id']] = true;
            if (isset($variant['title']) && (!is_string($variant['title']) || strlen($variant['title']) > 300 || preg_match('/[<>\x00-\x1f]/', $variant['title']))) return new WP_Error('digiforge_printify_preview_variant', 'Unsafe variant label.', ['status'=>502]);
            $placeholders = self::placeholders($variant);
            if ($placeholders instanceof WP_Error) return $placeholders;
            $variantBytes = wp_json_encode($variant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($variantBytes)) return new WP_Error('digiforge_printify_preview_shape', 'Variant evidence cannot be encoded.', ['status'=>502]);
            $variants[] = [
                'variant_id' => $variant['id'],
                'title' => is_string($variant['title'] ?? null) ? $variant['title'] : '',
                'is_available' => is_bool($variant['is_available'] ?? null) ? $variant['is_available'] : null,
                'print_area' => null, // Never infer geometry from product dimensions.
                'geometry_status' => 'UNKNOWN',
                // Catalog rectangles are useful without a template ZIP, but are
                // not reviewed safe/bleed geometry or production approval.
                'catalog_placeholders' => $placeholders,
                'placeholder_dimensions_status' => $placeholders === [] ? 'UNKNOWN' : 'OBSERVED',
                'variant_record_sha256' => hash('sha256', $variantBytes),
                'safe_zone_status' => 'UNKNOWN',
                'bleed_status' => 'UNKNOWN',
                'dpi_status' => 'UNKNOWN',
                'production_tolerance_status' => 'UNKNOWN',
            ];
        }

        // Shipping response is intentionally not echoed: provider-specific
        // country/rate mapping must be validated before public presentation.
        return [
            'blueprint_id' => $blueprintId,
            'print_provider_id' => $providerId,
            'variants' => $variants,
            'shipping_status' => 'UNKNOWN',
            'shipping_country_rates' => null,
            'persistence' => 'NONE',
            'certification' => 'NOT_CERTIFIED',
            'state' => 'DRAFT_UNVERIFIED',
            'mockup_rights' => 'UNKNOWN',
            'source_sha256' => $evidence['response_sha256'],
            'observed_at' => $evidence['observed_at'],
            'source_endpoint' => PrintifyCatalogReadContract::endpoint($request),
            'geometry_evidence_schema' => 'printify-catalog-rectangles-v1',
            // A provider-scoped catalog response alone cannot establish Choice
            // routing or an invariant physical composition across apparel sizes.
            'provider_routing_status' => 'UNKNOWN',
            'cross_variant_scaling_status' => 'UNKNOWN',
        ];
    }

    /** Documented API placeholder fields only; never derive DPI, safe zones or bleed. */
    private static function placeholders(array $variant): array|WP_Error
    {
        $error = static fn(): WP_Error => new WP_Error('digiforge_printify_preview_geometry', 'Invalid or conflicting catalog placeholder evidence.', ['status'=>502]);
        $methods = $variant['decoration_methods'] ?? null;
        if (array_key_exists('decoration_methods', $variant)) {
            if (!is_array($methods) || !array_is_list($methods) || count($methods) > 64) return $error();
            $seenMethods = [];
            foreach ($methods as $method) {
                if (!is_string($method) || preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $method) !== 1 || isset($seenMethods[$method])) return $error();
                $seenMethods[$method] = true;
            }
        }
        if (!array_key_exists('placeholders', $variant)) return [];
        $items = $variant['placeholders'];
        if (!is_array($items) || !array_is_list($items) || count($items) > 256) return $error();
        $out = []; $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) return $error();
            foreach (['position', 'decoration_method'] as $field) {
                if (!is_string($item[$field] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $item[$field]) !== 1) return $error();
            }
            foreach (['width', 'height'] as $field) {
                if (!is_int($item[$field] ?? null) || $item[$field] < 1 || $item[$field] > 2147483647) return $error();
            }
            $key = strtolower($item['position'])."\0".strtolower($item['decoration_method']);
            if (isset($seen[$key]) || (is_array($methods) && !in_array($item['decoration_method'], $methods, true))) return $error();
            $seen[$key] = true;
            $out[] = ['position'=>$item['position'], 'decoration_method'=>$item['decoration_method'], 'width'=>$item['width'], 'height'=>$item['height']];
        }
        return $out;
    }
}
