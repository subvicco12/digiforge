<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;

use DigiForge\POD\{PrintifyProductPipeline,ProductPackageRepository};

/** Current core-owned source preparation. Never supplies native mutation approval, counters or epochs. */
final class NativeBusinessMutationEvidence
{
    public static function version(): string { return '1.0.0'; }

    public static function resolve(ShopBinding $scope, int $actor, int $mapping, int $spec, string $planHash, ?string $expectedHash = null): array|\WP_Error
    {
        $current = self::current($scope, $actor, $mapping, $spec, $planHash, $expectedHash);
        return is_wp_error($current) ? $current : $current['evidence'];
    }

    /** Reuse only the sources already checked by the original resolver; no cache or weaker checks. */
    public static function sourceMaterial(ShopBinding $scope, int $actor, int $mapping, int $spec, string $planHash): array|\WP_Error
    {
        return self::current($scope, $actor, $mapping, $spec, $planHash, null);
    }

    private static function current(ShopBinding $scope, int $actor, int $mapping, int $spec, string $planHash, ?string $expectedHash): array|\WP_Error
    {
        if ($mapping < 1 || $spec < 1 || !self::digest($planHash) || ($expectedHash !== null && !self::digest($expectedHash))) return NativeFailure::error('business_mutation_identity');
        if ($scope->shopKey !== 'personalized_pod' || $scope->shopId !== 2) return NativeFailure::error('business_mutation_shop');
        $access = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_pod','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_research']);
        if (is_wp_error($access)) return $access;
        try {
            $plan = NativePlanEvidence::productPlan($scope, $actor, $planHash);
            if (is_wp_error($plan)) return $plan;
            if ($plan['operation'] !== 'IMAGE_UPLOAD') return NativeFailure::error('business_mutation_operation');
            $business = NativeBusinessEvidence::product($scope, $actor, $mapping);
            if (is_wp_error($business)) return $business;
            $artwork = NativeBusinessArtworkEvidence::resolve($scope, $actor, $mapping, $spec);
            if (is_wp_error($artwork)) return $artwork;
            if ($artwork['business_evidence_hash'] !== $business['evidence_hash'] || $plan['workflow_hash'] !== $artwork['supplier_workflow_hash']) return NativeFailure::error('business_mutation_source_join');
            $pipeline = new PrintifyProductPipeline();
            $sealed = $pipeline->plan($planHash);
            $package = (new ProductPackageRepository())->read('personalized_pod', $plan['package_hash']);
            if (is_wp_error($sealed) || is_wp_error($package)) return NativeFailure::error('business_mutation_plan_unavailable');
            if ($package['record']['artwork_version_hash'] !== $artwork['artwork_version_hash'] || ($package['record']['commercial_design_metadata']['design_hash'] ?? null) !== $artwork['design_factory_hash']) return NativeFailure::error('business_mutation_artwork_join');
            $selection = $sealed['selection'] ?? null;
            if (!is_array($selection) || ($selection['blueprint_id'] ?? null) !== $artwork['blueprint_id'] || ($selection['provider_id'] ?? null) !== $artwork['provider_id'] || !in_array($selection['variant_id'] ?? null, $artwork['variant_ids'], true)) return NativeFailure::error('business_mutation_provider_join');
            $request = $sealed['request'];
            $payload = NativePortContract::hash(['method'=>$request['method'],'endpoint'=>$request['endpoint'],'body'=>$request['body']]);
            // Every source is independently rechecked after the potentially expensive plan/package reads.
            $lastArtwork = NativeBusinessArtworkEvidence::resolve($scope, $actor, $mapping, $spec, $artwork['evidence_hash']);
            $lastPlan = NativePlanEvidence::productPlan($scope, $actor, $planHash);
            $lastBusiness = NativeBusinessEvidence::product($scope, $actor, $mapping, $business['evidence_hash']);
            $last = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_pod','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_research']);
            if (is_wp_error($lastArtwork) || is_wp_error($lastPlan) || is_wp_error($lastBusiness) || is_wp_error($last) || $lastPlan !== $plan || $last['session_fingerprint'] !== $access['session_fingerprint'] || $last['scope'] !== $access['scope']) return NativeFailure::error('business_mutation_changed');
            $result = ['schema'=>'DF_NATIVE_BUSINESS_MUTATION_EVIDENCE_1','version'=>self::version(),
                'scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'shop_key'=>$scope->shopKey,
                'integration_id'=>$scope->integrationId,'credential_reference'=>$scope->credential->integrationId,
                'provider_shop_id'=>$scope->providerShopId,'environment'=>$scope->environment,
                'product_id'=>$business['product_id'],'product_version_id'=>$business['product_version_id'],
                'provider_mapping_id'=>$mapping,'asset_spec_id'=>$spec,'plan_hash'=>$planHash,
                'package_hash'=>$plan['package_hash'],'artwork_version_hash'=>$artwork['artwork_version_hash'],
                'business_evidence_hash'=>$business['evidence_hash'],'artwork_evidence_hash'=>$artwork['evidence_hash'],
                'plan_evidence_hash'=>$plan['evidence_hash'],'operation'=>$plan['operation'],
                'payload_fingerprint'=>$payload,'plan_policy_hash'=>$plan['approval_hash'],
                'plan_policy_revision'=>$plan['approval_revision'],'authorization_revision'=>$scope->revision,
                'native_product_version'=>null,'native_version_counters_verified'=>false,'companion_payload_equivalence_verified'=>false,
                'business_package_binding_approved'=>false,'mutation_authorized'=>false,
                'independent_checkpoint_verified'=>false,'external_execution_authorized'=>false,'release_hold'=>true];
            $result['evidence_hash'] = NativePortContract::hash($result);
            if ($expectedHash !== null && !hash_equals($expectedHash, $result['evidence_hash'])) return NativeFailure::error('business_mutation_stale');
            return ['schema'=>'DF_NATIVE_BUSINESS_MUTATION_COMPONENTS_1','evidence'=>$result,
                'business_dependencies'=>$lastBusiness['dependency_hashes'],'artwork_dependency_hash'=>$lastArtwork['dependency_hash']];
        } catch (\Throwable) {
            return NativeFailure::error('business_mutation_unavailable');
        }
    }

    /** Transactional HELD candidate only. The source is checked before and after persistence, not an operational CAS. */
    public static function register(ShopBinding $scope, int $actor, int $mapping, int $spec, string $planHash, string $expectedHash): array|\WP_Error
    {
        if (!self::digest($expectedHash)) return NativeFailure::error('business_mutation_identity');
        $evidence = self::resolve($scope, $actor, $mapping, $spec, $planHash, $expectedHash);
        if (is_wp_error($evidence)) return $evidence;
        [$binding,$key] = self::heldIdentity($scope,$actor,$evidence);
        $journal = (new NativeHeldJournal())->register($binding, $key);
        if (is_wp_error($journal)) return $journal;
        $last = self::resolve($scope, $actor, $mapping, $spec, $planHash, $expectedHash);
        if (is_wp_error($last)) return NativeFailure::error('business_mutation_registration_unknown');
        $result = ['schema'=>'DF_NATIVE_BUSINESS_MUTATION_REGISTRATION_1','version'=>self::version(),
            'scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'evidence'=>$last,'journal'=>$journal,
            'approval_verified'=>false,'dispatch_permitted'=>false,'native_product_version'=>null,
            'native_version_counters_verified'=>false,'companion_payload_equivalence_verified'=>false,'native_contract_satisfied'=>false,
            'independent_checkpoint_verified'=>false,'external_execution_authorized'=>false,'release_hold'=>true];
        $result['evidence_hash'] = NativePortContract::hash($result);
        return $result;
    }

    /** Register or consume a reviewed local image-upload hold on the existing Core writer. */
    public static function transitionReviewedHold(ShopBinding $scope, int $actor, int $mapping, int $spec, string $plan,
        string $expectedEvidence, string $review, int $reviewRevision, ?int $expectedRevision = null, ?string $expectedHead = null): array|\WP_Error
    {
        if ($scope->environment !== 'test' || wp_get_environment_type() !== 'local' || !self::digest($expectedEvidence)
            || !self::digest($review) || $reviewRevision < 1 || (($expectedRevision === null) !== ($expectedHead === null))
            || ($expectedRevision !== null && ($expectedRevision < 1 || !self::digest($expectedHead)))) {
            return NativeFailure::error('business_reviewed_hold_identity');
        }
        $evidence = self::resolve($scope,$actor,$mapping,$spec,$plan,$expectedEvidence);
        if (is_wp_error($evidence)) return $evidence;
        $association = NativeBusinessMutationAssociation::read($scope,$actor,$mapping,$spec,$plan);
        if (is_wp_error($association)) return $association;
        if ($association['review_hash'] !== $review || $association['review_revision'] !== $reviewRevision
            || $association['current_source_evidence_hash'] !== $expectedEvidence) {
            return NativeFailure::error('business_reviewed_hold_stale');
        }
        [$binding,$key] = self::heldIdentity($scope,$actor,$evidence);
        $pin = ['mapping'=>$mapping,'spec'=>$spec,'plan'=>$plan,'review'=>$review,'revision'=>$reviewRevision,
            'source'=>$association['source']['source_hash']];
        $journal = new NativeHeldJournal();
        return $expectedRevision === null ? $journal->prepareScoped($binding,$key,$pin)
            : $journal->fenceScoped($binding,$expectedRevision,$expectedHead,$pin);
    }

    /** Validate the canonical admission attempt. Local evidence cannot satisfy the operational gate. */
    public static function checkCanonicalAdmission(ShopBinding $scope, array $binding, string $duplicateKey,
        int $mapping, int $spec, string $plan, string $review, ?int $sourceVersion = null): \WP_Error
    {
        $actor = $binding['actor_id'];
        $association = NativeBusinessMutationAssociation::read($scope,$actor,$mapping,$spec,$plan);
        if (is_wp_error($association)) return $association;
        $source = $association['source'];
        if ($review !== $association['review_hash'] || $binding['payload_fingerprint'] !== $source['payload_fingerprint']) {
            return NativeFailure::error('canonical_product_intent_mismatch');
        }

        // The producer resolves the sealed method, endpoint and body. The operation and
        // duplicate digests must bind that same evidence, rather than caller assertions.
        $evidence = $source + ['evidence_hash'=>$association['current_source_evidence_hash']];
        [$expected,$key] = self::heldIdentity($scope,$actor,$evidence);
        $claimed = array_replace($binding,['product_version'=>0,'expected_product_version'=>0]);
        if ($sourceVersion !== null) {
            if ($sourceVersion < 1 || $association['association_version'] !== $sourceVersion) {
                return NativeFailure::error('reviewed_source_revision_stale');
            }

            // The version belongs to the reviewed image source stream. It does
            // not describe the revision of an existing product at Printify.
            $expected['intent_fingerprint'] = NativePortContract::hash([
                'DF_REVIEWED_IMAGE_OPERATION_INTENT_1',$expected['intent_fingerprint'],$review,
                $association['review_revision'],$sourceVersion,
            ]);
            $expected['operation_id'] = NativePortContract::hash([
                'DF_REVIEWED_IMAGE_OPERATION_1',$key,$expected['payload_fingerprint'],$expected['intent_fingerprint'],
            ]);
        }
        if ($key !== $duplicateKey || NativePortContract::hash($expected) !== NativePortContract::hash($claimed)) {
            return NativeFailure::error('canonical_product_intent_mismatch');
        }
        if ($binding['product_version'] !== 0 || $binding['expected_product_version'] !== 0) {
            return NativeFailure::error('product_revision_authority_required');
        }

        return (new NativeHeldJournal())->checkScopedAdmission($binding,$key,[
            'mapping'=>$mapping,'spec'=>$spec,'plan'=>$plan,'review'=>$review,
            'revision'=>$association['review_revision'],'source'=>$source['source_hash'],
        ],$sourceVersion??0);
    }

    private static function heldIdentity(ShopBinding $scope, int $actor, array $evidence): array
    {
        $key = NativePortContract::hash(['DF_NATIVE_BUSINESS_MUTATION_CANDIDATE_1',$scope->shopKey,$scope->integrationId,$scope->providerShopId,$scope->environment,$evidence['product_id'],$evidence['product_version_id'],$evidence['operation']]);
        $binding = ['operation_id'=>NativePortContract::hash(['DF_NATIVE_BUSINESS_MUTATION_HELD_1',$key,$evidence['payload_fingerprint'],$evidence['evidence_hash']]),
            'payload_fingerprint'=>$evidence['payload_fingerprint'],'intent_fingerprint'=>$evidence['evidence_hash'],
            'scope_fingerprint'=>$scope->fingerprint(),'shop_id'=>$scope->shopId,'shop_key'=>$scope->shopKey,
            'integration_id'=>$scope->integrationId,'credential_reference'=>$scope->credential->integrationId,
            'provider_shop_id'=>$scope->providerShopId,'environment'=>$scope->environment,'actor_id'=>$actor,
            'authorization_revision'=>$scope->revision,'approval_reference'=>$evidence['plan_policy_hash'],
            // Unknown counter placeholders in an unapproved HELD record, not verified native version zero.
            'product_version'=>0,'expected_product_version'=>0];
        return [$binding,$key];
    }

    private static function digest(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
}
