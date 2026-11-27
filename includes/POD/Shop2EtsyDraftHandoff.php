<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Listings\EtsyDraftListingOperations;
use DigiForge\Listings\Validator;
use WP_Error;

/**
 * Pure held-export to Etsy draft plan adapter; no authorization or execution.
 * The authenticated caller MUST re-resolve ProductPackageRepository::export()
 * for the exact shop/package hash on the server immediately before calling.
 * Caller-supplied JSON, approvals and checksums are never approval authority.
 * Etsy shop identity and profile ownership must also be resolved by the caller.
 */
final class Shop2EtsyDraftHandoff
{
    /** @return array<string,mixed>|WP_Error */
    public static function prepare(array $heldExport, int $etsyShopId, array $declarations): array|WP_Error
    {
        try {
            Validator::structured($heldExport);
            $package = $heldExport['package'] ?? null;
            $approval = $heldExport['approval'] ?? null;
            $hash = $heldExport['package_hash'] ?? null;
            if (
                ($heldExport['schema'] ?? null) !== 'printify-native-held-review-export-v1'
                || ($heldExport['shop_key'] ?? null) !== 'personalized_pod'
                || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
                || !is_array($package) || !is_array($approval)
                || ($heldExport['release_hold'] ?? null) !== true
                || ($heldExport['external_execution_authorized'] ?? null) !== false
                || ($heldExport['external_execution_performed'] ?? null) !== false
                || ($heldExport['commercial_approved'] ?? null) !== false
                || ($heldExport['supplier_certified'] ?? null) !== false
                || ($approval['state'] ?? null) !== 'HUMAN_APPROVED'
                || ($approval['decision'] ?? null) !== 'APPROVE'
                || ($approval['evidence_hash'] ?? null) !== $hash
                || !is_int($approval['approved_by'] ?? null) || $approval['approved_by'] < 1
                || ($approval['publishing_enabled'] ?? null) !== false
                || ($approval['order_execution_enabled'] ?? null) !== false
            ) {
                return self::error('Exact server-resolved approved held Shop 2 export required.');
            }
            $controls = ['stop_all'=>true, 'external_lock'=>true, 'automation'=>false,
                'release_hold'=>true, 'order_approval'=>'MANUAL'];
            if (
                ($package['schema'] ?? null) !== 'approved-design-listing-package-v1'
                || ($package['shop_key'] ?? null) !== 'personalized_pod'
                || ($package['state'] ?? null) !== 'REVIEW_REQUIRED'
                || ($package['external_execution_authorized'] ?? null) !== false
                || ($package['external_execution_performed'] ?? null) !== false
                || !in_array($package['fixture_id'] ?? null, ['FL-02','FL-03','FL-04'], true)
                || Validator::canonicalJson($package['controls'] ?? []) !== Validator::canonicalJson($controls)
            ) {
                return self::error('Held Shop 2 package scope and controls required.');
            }
            $seal = $package['sha256'] ?? null;
            unset($package['sha256']);
            if (!is_string($seal) || preg_match('/^[a-f0-9]{64}$/D', $seal) !== 1
                || !hash_equals($seal, hash('sha256', Validator::canonicalJson($package)))) {
                return self::error('Package snapshot integrity conflict.');
            }
            $gates = $heldExport['remaining_gates'] ?? null;
            if (!is_array($gates) || !array_is_list($gates)) return self::error('Explicit remaining release gates required.');
            foreach ($gates as $gate) {
                if (!is_string($gate) || preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $gate) !== 1) return self::error('Invalid remaining release gate.');
            }
            $keys = ['quantity','who_made','when_made','taxonomy_id','shipping_profile_id','readiness_state_id'];
            if (array_diff(array_keys($declarations), $keys) || array_diff($keys, array_keys($declarations))) {
                return self::error('Only exact Etsy physical draft declarations are accepted.');
            }
            foreach (['quantity','taxonomy_id','shipping_profile_id','readiness_state_id'] as $key) {
                if (!is_int($declarations[$key]) || $declarations[$key] < 1) return self::error('Positive explicit Etsy quantity and profile/taxonomy identifiers required.');
            }
            $when = ['made_to_order','2020_2026','2010_2019','2007_2009','before_2007','2000_2006',
                '1990s','1980s','1970s','1960s','1950s','1940s','1930s','1920s','1910s','1900s','1800s','1700s','before_1700'];
            if (!in_array($declarations['who_made'], ['i_did','someone_else','collective'], true)
                || !in_array($declarations['when_made'], $when, true)) return self::error('Supported explicit Etsy maker and production period required.');
            $listing = $package['listing'] ?? [];
            if (!is_array($listing) || !self::text($listing['title'] ?? null, 140)
                || !self::text($listing['description'] ?? null, 20000)) return self::error('Bounded approved listing text required.');
            $tags = $listing['tags'] ?? null;
            if (!is_array($tags) || !array_is_list($tags) || count($tags) !== 13) return self::error('Exactly 13 approved Etsy tags required.');
            $seen = [];
            foreach ($tags as $tag) {
                if (!self::text($tag, 20)) return self::error('Bounded approved Etsy tags required.');
                $key = mb_strtolower($tag, 'UTF-8');
                if (isset($seen[$key])) return self::error('Distinct approved Etsy tags required.');
                $seen[$key] = true;
            }
            $price = $package['economics']['assumptions']['sale_price'] ?? null;
            if ((!is_int($price) && !is_float($price)) || !is_finite((float) $price) || $price <= 0
                || abs($price * 100 - round($price * 100)) > 0.000001) return self::error('Positive finite approved sale price in whole cents required.');
            // Shipping, tax and costs are never inferred. Native Printify placement,
            // images and personalization are separate reviewed surfaces, not Etsy fields.
            $payload = $declarations + ['title'=>$listing['title'], 'description'=>$listing['description'],
                'tags'=>$tags, 'price'=>number_format((float) $price, 2, '.', ''), 'type'=>'physical', 'is_supply'=>false];
            $operation = EtsyDraftListingOperations::create($etsyShopId, $payload);
            if ($operation instanceof WP_Error) return $operation;
            return ['schema'=>'shop2-etsy-draft-handoff-v1', 'state'=>'SHOP2_ETSY_DRAFT_HANDOFF_PREPARED',
                'shop_key'=>'personalized_pod', 'package_hash'=>$hash, 'remaining_gates'=>$gates,
                'draft_operation'=>$operation, 'release_hold'=>true, 'publish_permitted'=>false,
                'network_request_permitted'=>false, 'external_execution_authorized'=>false,
                'external_execution_performed'=>false];
        } catch (\Throwable) {
            return self::error('Bounded valid held export and explicit draft declarations required.');
        }
    }

    private static function text(mixed $value, int $limit): bool
    {
        return is_string($value) && trim($value) !== '' && trim($value) === $value
            && preg_match('//u', $value) === 1 && mb_strlen($value, 'UTF-8') <= $limit
            && strip_tags($value) === $value && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value) === 0;
    }

    private static function error(string $message): WP_Error
    {
        return new WP_Error('shop2_etsy_draft_handoff_denied', $message, ['status'=>409,
            'release_hold'=>true, 'publish_permitted'=>false, 'external_execution_authorized'=>false]);
    }
}
