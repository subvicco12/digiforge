<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;

use DigiForge\POD\PrintifyApiReadWorkflow;

/** Observed provider identity joined to approved local artwork/template; never a native version or permit. */
final class NativeSupplierEvidence
{
    public static function resolve(ShopBinding $scope, int $actor, int $mapping, int $spec, string $receipt, ?string $expectedHash = null): array|\WP_Error
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $receipt) !== 1 || ($expectedHash !== null && preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1)) return NativeFailure::error('supplier_source_identity');
        $business = NativeBusinessEvidence::product($scope, $actor, $mapping);
        if (is_wp_error($business)) return $business;
        $artwork = NativeBusinessArtworkEvidence::resolve($scope, $actor, $mapping, $spec);
        if (is_wp_error($artwork)) return $artwork;
        $authority = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_products','manage_digiforge_pod']);
        if (is_wp_error($authority)) return $authority;
        try {
            $reader = new PrintifyApiReadWorkflow();
            $source = $reader->productSource($receipt);
            if (is_wp_error($source)) return $source;
            $r = $source['receipt'];
            if ($r['shop_key'] !== $scope->shopKey || $r['integration_id'] !== $scope->integrationId || $r['provider_shop_id'] !== $scope->providerShopId || $r['connection_binding_hash'] !== $scope->bindingHash || NativePortContract::hash($source['connection_scope']) !== NativePortContract::hash($authority['scope'])) return NativeFailure::error('supplier_source_scope');
            $product = $r['data'];
            $variantKey = $business['provider_variant_key'];
            if ($product['id'] !== $business['provider_product_key'] || preg_match('/^[1-9][0-9]*$/D', $variantKey) !== 1 || (string)(int)$variantKey !== $variantKey || $product['blueprint_id'] !== $artwork['blueprint_id'] || $product['print_provider_id'] !== $artwork['provider_id'] || $artwork['variant_ids'] !== [(int)$variantKey]) return NativeFailure::error('supplier_product_mapping');
            $variants = array_values(array_filter($product['variants'], static fn(array $v): bool => $v['id'] === (int)$variantKey));
            if (count($variants) !== 1 || $variants[0]['is_enabled'] !== true) return NativeFailure::error('supplier_variant_unavailable');
            $areas = array_values(array_filter($product['print_areas'], static fn(array $a): bool => in_array((int)$variantKey, $a['variant_ids'], true)));
            if (count($areas) !== 1) return NativeFailure::error('supplier_print_area_ambiguous');
            $link = (new \DigiForge\POD\Shop2ProductWorkflow())->read($artwork['supplier_workflow_hash']);
            if (is_wp_error($link)) return $link;
            $selected = $link['review']['evidence']['print_areas'];
            if (count($selected) !== 1 || count($areas[0]['placeholders']) !== 1 || $areas[0]['placeholders'][0]['position'] !== ($selected[0]['facts']['Print Area'] ?? null)) return NativeFailure::error('supplier_placement_mismatch');
            $lastBusiness = NativeBusinessEvidence::product($scope, $actor, $mapping, $business['evidence_hash']);
            $lastArtwork = NativeBusinessArtworkEvidence::resolve($scope, $actor, $mapping, $spec, $artwork['evidence_hash']);
            $lastSource = $reader->productSource($receipt);
            $last = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_products','manage_digiforge_pod']);
            if (is_wp_error($lastBusiness) || is_wp_error($lastArtwork) || is_wp_error($lastSource) || is_wp_error($last) || $lastSource !== $source || $last['scope'] !== $authority['scope'] || $last['session_fingerprint'] !== $authority['session_fingerprint']) return NativeFailure::error('supplier_source_changed');
            $out = [
                'schema'=>'DF_NATIVE_SUPPLIER_PRODUCT_1', 'evidence_class'=>'CURRENT_PROTECTED_PROVIDER_OBSERVATION',
                'scope_fingerprint'=>$scope->fingerprint(), 'business_evidence_hash'=>$business['evidence_hash'],
                'artwork_evidence_hash'=>$artwork['evidence_hash'], 'product_version_id'=>$business['product_version_id'],
                'provider_mapping_id'=>$mapping, 'provider_product_id'=>$product['id'],
                'blueprint_id'=>$product['blueprint_id'], 'provider_id'=>$product['print_provider_id'], 'variant_id'=>(int)$variantKey,
                'source_receipt_hash'=>$receipt, 'source_approval_revision'=>$source['approval_revision'],
                'source_producer_audit_id'=>$source['producer_audit_id'], 'source_producer_actor_id'=>$source['producer_actor_id'],
                'observed_at'=>$r['observed_at'], 'observed_variant_price_minor_units'=>$variants[0]['price'],
                'currency'=>null, 'base_cost'=>null, 'shipping_cost'=>null, 'sku'=>null,
                'provider_product_identity_observed'=>true, 'native_product_version'=>null,
                'provider_print_area_hash'=>NativePortContract::hash($areas[0]),
                'revised_artwork_placement_verified'=>false, 'routing_verified'=>false,
                'supplier_certified'=>false, 'physical_safe_zones_verified'=>false, 'native_typography_verified'=>false,
                'independent_checkpoint_verified'=>false, 'external_execution_authorized'=>false, 'release_hold'=>true,
            ];
            $out['evidence_hash'] = NativePortContract::hash($out);
            return $expectedHash !== null && !hash_equals($expectedHash, $out['evidence_hash']) ? NativeFailure::error('supplier_source_stale') : $out;
        } catch (\Throwable) {
            return NativeFailure::error('supplier_source_unavailable');
        }
    }
}
