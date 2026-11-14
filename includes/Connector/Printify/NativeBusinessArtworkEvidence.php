<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;

use DigiForge\Database\Tables;
use DigiForge\POD\Shop2ProductWorkflow;
use DigiForge\Research\DesignFactoryRepository;

/** Explicit approved asset-spec join, never remote product identity or dispatch authority. */
final class NativeBusinessArtworkEvidence
{
    /** Reuse the original approved export; this wrapper never approves its new business association. */
    public static function heldPackage(ShopBinding $scope, int $actor, int $mapping, int $spec, string $packageHash): array|\WP_Error
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $packageHash) !== 1) return NativeFailure::error('business_package_identity');
        $artwork = self::resolve($scope, $actor, $mapping, $spec);
        if (is_wp_error($artwork)) return $artwork;
        try {
            $repository = new \DigiForge\POD\ProductPackageRepository();
            $package = $repository->read('personalized_pod', $packageHash);
            if (is_wp_error($package)) return $package;
            if ($package['record']['artwork_version_hash'] !== $artwork['artwork_version_hash'] || ($package['record']['commercial_design_metadata']['design_hash'] ?? null) !== $artwork['design_factory_hash']) return NativeFailure::error('business_package_artwork');
            $workflow = new Shop2ProductWorkflow();
            $link = $workflow->read($artwork['supplier_workflow_hash']);
            if (is_wp_error($link)) return $link;
            $raw = $workflow->export($link, $packageHash);
            if (is_wp_error($raw)) return $raw;
            $current = $workflow->export($link, $packageHash);
            if (is_wp_error($current) || !hash_equals($raw, $current)) return NativeFailure::error('business_package_changed');
            // Final export may be expensive: do not return authority captured before it.
            $again = self::resolve($scope, $actor, $mapping, $spec, $artwork['evidence_hash']);
            if (is_wp_error($again)) return $again;
            $authority = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_products','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_listings']);
            if (is_wp_error($authority)) return $authority;
            $out = ['schema'=>'DF_NATIVE_BUSINESS_HELD_PACKAGE_1', 'shop_key'=>'personalized_pod',
                'package_hash'=>$packageHash, 'business_artwork_evidence_hash'=>$artwork['evidence_hash'],
                'product_version_id'=>$artwork['product_version_id'], 'provider_mapping_id'=>$mapping,
                'original_held_export_sha256'=>hash('sha256', $raw), 'held_export'=>json_decode($raw, true, 32, JSON_THROW_ON_ERROR),
                'business_package_binding_approved'=>false, 'publication_authorized'=>false,
                'buyer_specific_production_approval_required'=>true, 'external_execution_authorized'=>false,
                'release_hold'=>true];
            $out['evidence_hash'] = NativePortContract::hash($out);
            return $out;
        } catch (\Throwable) {
            return NativeFailure::error('business_package_unavailable');
        }
    }

    public static function resolve(ShopBinding $scope, int $actor, int $mapping, int $spec, ?string $expectedHash = null): array|\WP_Error
    {
        if ($spec < 1 || ($expectedHash !== null && preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1)) return NativeFailure::error('artwork_business_identity');
        $business = NativeBusinessEvidence::product($scope, $actor, $mapping);
        if (is_wp_error($business)) return $business;
        global $wpdb;
        $started = false;
        try {
            foreach ([Tables::asset_specs(), Tables::production_plan_assets()] as $table) {
                $wpdb->last_error = '';
                $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
                if ($wpdb->last_error !== '' || strtoupper((string)$engine) !== 'INNODB') return NativeFailure::error('artwork_business_storage');
                $ddl = $wpdb->get_row('SHOW CREATE TABLE '.$table, ARRAY_N);
                if ($wpdb->last_error !== '' || !is_array($ddl) || !str_starts_with(strtoupper((string)($ddl[1] ?? '')), 'CREATE TABLE ')) return NativeFailure::error('artwork_business_shadow');
            }
            if (!self::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !self::statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')) return NativeFailure::error('artwork_business_snapshot');
            $started = true;
            $material = self::material($business, $spec);
            if (is_wp_error($material)) return $material;
            if (!self::statement('COMMIT')) return NativeFailure::error('artwork_business_commit_unknown');
            $started = false;
            $again = self::material($business, $spec);
            $current = NativeBusinessEvidence::product($scope, $actor, $mapping, $business['evidence_hash']);
            if (is_wp_error($again) || is_wp_error($current) || $material !== $again) return NativeFailure::error('artwork_business_changed');
            $b = $material['binding'];
            $selection = $material['supplier']['record'];
            $result = [
                'schema'=>'DF_NATIVE_BUSINESS_ARTWORK_1', 'evidence_class'=>'CURRENT_PROTECTED_SNAPSHOT',
                'scope_fingerprint'=>$scope->fingerprint(), 'business_evidence_hash'=>$business['evidence_hash'],
                'product_version_id'=>$business['product_version_id'], 'production_plan_id'=>$business['production_plan_id'],
                'provider_mapping_id'=>$mapping, 'asset_spec_id'=>$spec, 'spec_approval_event_id'=>(int)$material['approval']['id'],
                'design_factory_hash'=>$b['design_factory_hash'], 'artwork_version_hash'=>$b['artwork_version_hash'],
                'supplier_workflow_hash'=>$b['supplier_workflow_hash'],
                'svg_sha256'=>hash('sha256', $material['handoff']['artwork']['record']['input']['artwork']['svg']),
                'raster_references'=>$material['handoff']['design_metadata']['raster_references'],
                'product_family'=>$selection['family'], 'blueprint_id'=>$selection['blueprint_id'],
                'provider_id'=>$selection['provider_id'], 'variant_ids'=>[$selection['variant_id']],
                'output_dpi'=>300, 'width_px'=>(int)$material['spec']['width_px'], 'height_px'=>(int)$material['spec']['height_px'],
                'native_regions'=>$material['handoff']['design_metadata']['specification']['layout']['native_regions'],
                'dependency_hash'=>NativePortContract::hash($material),
                'saved_product_identity_verified'=>false, 'supplier_certified'=>false,
                'physical_safe_zones_verified'=>false, 'native_typography_verified'=>false,
                'allocation_verified'=>false, 'independent_checkpoint_verified'=>false,
                'external_execution_authorized'=>false, 'release_hold'=>true,
            ];
            $result['evidence_hash'] = NativePortContract::hash($result);
            return $expectedHash !== null && !hash_equals($expectedHash, $result['evidence_hash']) ? NativeFailure::error('artwork_business_stale') : $result;
        } catch (\Throwable) {
            return NativeFailure::error('artwork_business_unavailable');
        } finally {
            if ($started) {
                try { if (!self::statement('ROLLBACK')) return NativeFailure::error('artwork_business_cleanup_unknown'); }
                catch (\Throwable) { return NativeFailure::error('artwork_business_cleanup_unknown'); }
            }
        }
    }

    private static function statement(string $sql): bool
    {
        global $wpdb;
        $wpdb->last_error = '';
        return $wpdb->query($sql) !== false && $wpdb->last_error === '';
    }

    private static function material(array $business, int $specId): array|\WP_Error
    {
        global $wpdb;
        $wpdb->last_error = '';
        $spec = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::asset_specs().' WHERE id=%d', $specId), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($spec) || ($spec['state'] ?? null) !== 'APPROVED' || (string)$spec['product_version_id'] !== (string)$business['product_version_id'] || (string)$spec['dpi'] !== '300' || ($spec['format'] ?? null) !== 'svg') return NativeFailure::error('artwork_business_spec');
        $content = json_decode((string)$spec['content_requirements'], true);
        $binding = $content['native_artwork_binding'] ?? null;
        if (!is_array($binding) || count($binding) !== 5 || ($binding['schema'] ?? null) !== 'DF_NATIVE_ARTWORK_BINDING_1' || ($binding['shop_key'] ?? null) !== 'personalized_pod') return NativeFailure::error('artwork_business_binding');
        foreach (['design_factory_hash','supplier_workflow_hash','artwork_version_hash'] as $key) if (!is_string($binding[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $binding[$key]) !== 1) return NativeFailure::error('artwork_business_binding');
        $wpdb->last_error = '';
        $link = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::production_plan_assets().' WHERE production_plan_id=%d AND asset_spec_id=%d AND is_required=1', $business['production_plan_id'], $specId), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($link)) return NativeFailure::error('artwork_business_plan_link');
        $wpdb->last_error = '';
        $approval = $wpdb->get_row($wpdb->prepare('SELECT id,actor_id,context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s ORDER BY id DESC LIMIT 1', 'production_state_changed', 'spec', (string)$specId), ARRAY_A);
        $context = is_array($approval) ? json_decode((string)$approval['context'], true) : null;
        if ($wpdb->last_error !== '' || !is_array($approval) || (int)$approval['actor_id'] < 1 || (int)$approval['id'] < 1 || (string)(int)$approval['id'] !== (string)$approval['id'] || ($context['to'] ?? null) !== 'APPROVED' || (int)$approval['id'] >= $business['plan_approval_event_id']) return NativeFailure::error('artwork_business_spec_approval');
        $wpdb->last_error = '';
        $linked = $wpdb->get_row($wpdb->prepare('SELECT id,actor_id,context FROM '.Tables::audit_log()." WHERE event_type=%s AND object_type=%s AND object_id=%s AND JSON_VALID(context) AND JSON_UNQUOTE(JSON_EXTRACT(context,'$.asset_spec_id'))=%s ORDER BY id DESC LIMIT 1", 'production_asset_linked', 'production_plan', (string)$business['production_plan_id'], (string)$specId), ARRAY_A);
        $linkContext = is_array($linked) ? json_decode((string)$linked['context'], true) : null;
        if ($wpdb->last_error !== '' || !is_array($linked) || (int)$linked['actor_id'] < 1 || (int)$linked['id'] < 1 || (string)(int)$linked['id'] !== (string)$linked['id'] || ($linkContext['required'] ?? null) !== true || (int)$linked['id'] >= $business['plan_approval_event_id']) return NativeFailure::error('artwork_business_link_approval');
        $factory = new DesignFactoryRepository();
        $handoff = $factory->handoff('personalized_pod', $binding['design_factory_hash']);
        if (is_wp_error($handoff)) return $handoff;
        if (($handoff['artwork']['version_hash'] ?? null) !== $binding['artwork_version_hash']) return NativeFailure::error('artwork_business_version');
        $workflow = new Shop2ProductWorkflow();
        $supplier = $workflow->read($binding['supplier_workflow_hash']);
        if (is_wp_error($supplier)) return $supplier;
        $valid = $workflow->context($supplier, $handoff['design_metadata']['commercial_hash']);
        if (is_wp_error($valid)) return $valid;
        $geometry = $handoff['design_metadata']['geometry'];
        if ($geometry['family'] !== $supplier['record']['family'] || (string)$geometry['width_px'] !== (string)$spec['width_px'] || (string)$geometry['height_px'] !== (string)$spec['height_px']) return NativeFailure::error('artwork_business_geometry');
        $valid = $workflow->geometry($supplier, $geometry['width_px'], $geometry['height_px']);
        if (is_wp_error($valid)) return $valid;
        return ['spec'=>$spec, 'link'=>$link, 'approval'=>$approval, 'link_approval'=>$linked, 'binding'=>$binding, 'handoff'=>$handoff, 'supplier'=>$supplier];
    }
}
