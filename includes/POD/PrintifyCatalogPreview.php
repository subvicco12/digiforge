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
        $variants = []; $seen = [];

        foreach ($response['variants'] as $variant) {
            if (!is_array($variant) || !isset($variant['id']) || !is_int($variant['id']) || $variant['id'] < 1) {
                return new WP_Error('digiforge_printify_preview_variant', 'Invalid variant identity.', ['status' => 502]);
            }
            if (isset($seen[$variant['id']])) return new WP_Error('digiforge_printify_preview_variant', 'Ambiguous variant identity.', ['status'=>502]);
            $seen[$variant['id']] = true;
            if (isset($variant['title']) && (!is_string($variant['title']) || strlen($variant['title']) > 300 || preg_match('/[<>\x00-\x1f]/', $variant['title']))) return new WP_Error('digiforge_printify_preview_variant', 'Unsafe variant label.', ['status'=>502]);
            $variants[] = [
                'variant_id' => $variant['id'],
                'title' => is_string($variant['title'] ?? null) ? $variant['title'] : '',
                'is_available' => is_bool($variant['is_available'] ?? null) ? $variant['is_available'] : null,
                'print_area' => null, // Never infer geometry from product dimensions.
                'geometry_status' => 'UNKNOWN',
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
        ];
    }
}
