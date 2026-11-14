<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;

use DigiForge\POD\{PrintifyProductPipeline,PrintifyApiReadWorkflow,PrintifyProductRequest,ProductPackageRepository};
use DigiForge\Database\Tables;

/** Protected existing-product PRICE-UPDATE source facts, never mutation admission. */
final class NativeExistingProductMutationEvidence
{
    public static function version(): string { return '1.0.0'; }

    public static function resolve(ShopBinding $scope, int $actor, int $mapping, int $spec, string $planHash, string $readReceipt, ?string $expectedHash = null): array|\WP_Error
    {
        if ($scope->shopId !== 2 || $scope->shopKey !== 'personalized_pod' || $mapping < 1 || $spec < 1 || !self::digest($planHash) || !self::digest($readReceipt) || ($expectedHash !== null && !self::digest($expectedHash))) return self::deny('identity');
        $authority = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_products','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_listings']);
        if (is_wp_error($authority)) return $authority;
        try {
            $plan = NativePlanEvidence::productPlan($scope, $actor, $planHash);
            if (is_wp_error($plan)) return $plan;
            if ($plan['operation'] !== 'PRODUCT_UPDATE') return self::deny('operation');
            $pipeline = new PrintifyProductPipeline();
            $sealed = $pipeline->plan($planHash);
            if (is_wp_error($sealed)) return $sealed;
            $options = $sealed['options'] ?? null;
            if (!is_array($options) || count($options) !== 2 || array_diff(array_keys($options), ['product_receipt','price_minor']) !== [] || !self::digest($options['product_receipt'] ?? null) || !is_int($options['price_minor'] ?? null)) return self::deny('update_options');
            $supplier = NativeSupplierEvidence::resolve($scope, $actor, $mapping, $spec, $readReceipt);
            if (is_wp_error($supplier)) return $supplier;
            $business = NativeBusinessEvidence::product($scope, $actor, $mapping, $supplier['business_evidence_hash']);
            $artwork = NativeBusinessArtworkEvidence::resolve($scope, $actor, $mapping, $spec, $supplier['artwork_evidence_hash']);
            if (is_wp_error($business) || is_wp_error($artwork)) return self::deny('source_changed');
            $package = (new ProductPackageRepository())->read($scope->shopKey, $plan['package_hash']);
            if (is_wp_error($package)) return $package;
            if ($plan['workflow_hash'] !== $artwork['supplier_workflow_hash'] || $package['record']['artwork_version_hash'] !== $artwork['artwork_version_hash'] || ($package['record']['commercial_design_metadata']['design_hash'] ?? null) !== $artwork['design_factory_hash']) return self::deny('package_artwork');
            $selection = $sealed['selection'] ?? null;
            if (!is_array($selection) || count($selection) !== 3 || ($selection['blueprint_id'] ?? null) !== $supplier['blueprint_id'] || ($selection['provider_id'] ?? null) !== $supplier['provider_id'] || ($selection['variant_id'] ?? null) !== $supplier['variant_id']) return self::deny('selection');
            $reader = new PrintifyApiReadWorkflow();
            $source = $reader->productSource($readReceipt);
            if (is_wp_error($source)) return $source;
            if ($source['approval_revision'] !== $supplier['source_approval_revision'] || $source['producer_audit_id'] !== $supplier['source_producer_audit_id'] || $source['producer_actor_id'] !== $supplier['source_producer_actor_id'] || $source['receipt']['data']['id'] !== $supplier['provider_product_id'] || $source['receipt']['observed_at'] !== $supplier['observed_at']) return self::deny('read_source_changed');
            if (!self::timestamp($supplier['observed_at'])) return self::deny('observation_time');
            $adoption = self::adoption($pipeline, $scope, $sealed, $options['product_receipt'], $readReceipt, $source['receipt']['data'], $source['receipt']['observed_at'], $source['producer_audit_id']);
            if (is_wp_error($adoption)) return $adoption;
            $variants = [];
            $matched = 0;
            foreach ($source['receipt']['data']['variants'] as $variant) {
                if (!is_int($variant['id'] ?? null) || !is_int($variant['price'] ?? null) || !is_bool($variant['is_enabled'] ?? null)) return self::deny('variant_shape');
                $selected = $variant['id'] === $supplier['variant_id'];
                if ($selected) ++$matched;
                $variants[] = ['id'=>$variant['id'],'price'=>$selected ? $options['price_minor'] : $variant['price'],'is_enabled'=>$variant['is_enabled']];
            }
            if ($matched !== 1) return self::deny('variant_identity');
            $expected = PrintifyProductRequest::prepare('PRODUCT_UPDATE', $scope->providerShopId, ['product_id'=>$supplier['provider_product_id'],'variants'=>$variants,'known_variant_ids'=>array_column($variants, 'id')]);
            if (NativePortContract::hash($expected) !== NativePortContract::hash($sealed['request'])) return self::deny('wire_request');
            $lastSupplier = NativeSupplierEvidence::resolve($scope, $actor, $mapping, $spec, $readReceipt, $supplier['evidence_hash']);
            $lastPlan = NativePlanEvidence::productPlan($scope, $actor, $planHash);
            $lastSource = $reader->productSource($readReceipt);
            $lastAdoption = self::adoption($pipeline, $scope, $sealed, $options['product_receipt'], $readReceipt, $source['receipt']['data'], $source['receipt']['observed_at'], $source['producer_audit_id']);
            $lastAuthority = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_products','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_listings']);
            if (is_wp_error($lastSupplier) || is_wp_error($lastPlan) || is_wp_error($lastSource) || is_wp_error($lastAdoption) || is_wp_error($lastAuthority) || $lastPlan !== $plan || $lastSource !== $source || $lastAdoption !== $adoption || $lastAuthority['scope'] !== $authority['scope'] || $lastAuthority['session_fingerprint'] !== $authority['session_fingerprint']) return self::deny('changed');
            $result = ['schema'=>'DF_NATIVE_EXISTING_PRODUCT_MUTATION_EVIDENCE_1','version'=>self::version(),
                'scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'shop_key'=>$scope->shopKey,
                'integration_id'=>$scope->integrationId,'credential_reference'=>$scope->credential->integrationId,
                'provider_shop_id'=>$scope->providerShopId,'environment'=>$scope->environment,
                'product_id'=>$business['product_id'],'product_version_id'=>$business['product_version_id'],
                'provider_mapping_id'=>$mapping,'asset_spec_id'=>$spec,'operation'=>'PRODUCT_UPDATE',
                'plan_hash'=>$planHash,'package_hash'=>$plan['package_hash'],'artwork_version_hash'=>$artwork['artwork_version_hash'],
                'supplier_evidence_hash'=>$supplier['evidence_hash'],'provider_product_id'=>$supplier['provider_product_id'],
                'blueprint_id'=>$supplier['blueprint_id'],'provider_id'=>$supplier['provider_id'],'variant_id'=>$supplier['variant_id'],
                'price_minor'=>$options['price_minor'],'currency'=>null,'payload_fingerprint'=>NativePortContract::hash(['method'=>$expected['method'],'endpoint'=>$expected['endpoint'],'body'=>$expected['body']]),
                'product_read_receipt_hash'=>$readReceipt,'source_read_policy_revision'=>$source['approval_revision'],
                'source_read_producer_audit_id'=>$source['producer_audit_id'],'source_read_producer_actor_id'=>$source['producer_actor_id'],
                'adopted_product_receipt_hash'=>$options['product_receipt'],'adoption_producer_audit_id'=>$adoption['audit_id'],
                'adoption_producer_actor_id'=>$adoption['actor_id'],'observed_at'=>$supplier['observed_at'],'adopted_at'=>$adoption['created_at'],
                'plan_policy_hash'=>$plan['approval_hash'],'plan_policy_revision'=>$plan['approval_revision'],
                'native_product_version'=>null,'provider_currentness_verified'=>false,'provider_version_cas_verified'=>false,
                'revalidation_before_mutation_required'=>true,'business_package_binding_approved'=>false,'source_approval_cas_verified'=>false,
                'mutation_authorized'=>false,'dispatch_permitted'=>false,'independent_checkpoint_verified'=>false,
                'supplier_certified'=>false,'external_execution_authorized'=>false,'release_hold'=>true];
            $result['evidence_hash'] = NativePortContract::hash($result);
            return $expectedHash !== null && !hash_equals($expectedHash, $result['evidence_hash']) ? self::deny('stale') : $result;
        } catch (\Throwable) {
            return self::deny('unavailable');
        }
    }

    private static function adoption(PrintifyProductPipeline $pipeline, ShopBinding $scope, array $plan, string $adoptedHash, string $sourceHash, array $observed, string $observedAt, int $sourceAuditId): array|\WP_Error
    {
        $receipt = $pipeline->receipt($adoptedHash);
        if (is_wp_error($receipt)) return $receipt;
        if (($receipt['source_read_receipt'] ?? null) !== $sourceHash || ($receipt['operation'] ?? null) !== 'PRODUCT_READ' || ($receipt['status'] ?? null) !== 'SUCCEEDED' || ($receipt['network_request_attempted'] ?? null) !== false || ($receipt['audit_required'] ?? null) !== true || ($receipt['observed_at'] ?? null) !== $observedAt || NativePortContract::hash($receipt['data']) !== NativePortContract::hash($observed)) return self::deny('adoption_source');
        foreach (['workflow','package','integration_id','shop_id'] as $key) if (($receipt[$key] ?? null) !== ($plan[$key] ?? null)) return self::deny('adoption_package');
        if (($receipt['connection_binding_hash'] ?? null) !== ($plan['connection_scope']['binding_hash'] ?? null)) return self::deny('adoption_scope');
        $adoptedPlan = $pipeline->plan($receipt['plan_hash']);
        if (is_wp_error($adoptedPlan)) return $adoptedPlan;
        $expected = PrintifyProductRequest::prepare('PRODUCT_READ', $scope->providerShopId, ['product_id'=>$observed['id']]);
        if (($adoptedPlan['adopted_read_receipt'] ?? null) !== $sourceHash || !is_int($adoptedPlan['created_by'] ?? null) || $adoptedPlan['created_by'] < 1 || NativePortContract::hash($adoptedPlan['request']) !== NativePortContract::hash($expected) || !self::timestamp($adoptedPlan['created_at'] ?? null)) return self::deny('adoption_plan');
        foreach (['workflow','package','integration_id','shop_id'] as $key) if (($adoptedPlan[$key] ?? null) !== ($plan[$key] ?? null)) return self::deny('adoption_plan_scope');
        if (($adoptedPlan['binding_state'] ?? null) !== 'VERIFIED_LOCAL_BINDING' || !is_array($adoptedPlan['connection_scope'] ?? null) || NativePortContract::hash($adoptedPlan['connection_scope']) !== NativePortContract::hash($plan['connection_scope'])) return self::deny('adoption_plan_binding');
        $context = ['source_read_receipt'=>$sourceHash,'receipt_hash'=>$adoptedHash,'plan_hash'=>$receipt['plan_hash']];
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,actor_id,CASE WHEN OCTET_LENGTH(context)<=4096 THEN context ELSE NULL END AS context FROM '.Tables::audit_log().' WHERE BINARY event_type=BINARY %s AND BINARY object_type=BINARY %s AND BINARY object_id=BINARY %s ORDER BY id LIMIT 2', 'printify_product_read_adopted', 'printify_product', $receipt['plan_hash']), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows) || count($rows) !== 1 || preg_match('/^[1-9][0-9]*$/D', (string)$rows[0]['id']) !== 1 || (int)$rows[0]['id'] <= $sourceAuditId || (int)$rows[0]['actor_id'] !== $adoptedPlan['created_by']) return self::deny('adoption_producer');
        $actual = json_decode((string)$rows[0]['context'], true);
        if (!is_array($actual) || NativePortContract::hash($actual) !== NativePortContract::hash($context)) return self::deny('adoption_audit');
        return ['audit_id'=>(int)$rows[0]['id'],'actor_id'=>$adoptedPlan['created_by'],'adoption_plan_hash'=>$receipt['plan_hash'],'created_at'=>$adoptedPlan['created_at']];
    }

    private static function timestamp(mixed $value): bool
    {
        if (!is_string($value)) return false;
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        return $time !== false && $time->format('c') === $value && $time->getTimestamp() <= time();
    }

    private static function digest(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private static function deny(string $reason): \WP_Error { return NativeFailure::error('existing_product_'.$reason); }
}
