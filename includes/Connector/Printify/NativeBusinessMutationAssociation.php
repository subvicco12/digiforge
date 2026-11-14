<?php

declare(strict_types=1);

namespace DigiForge\Connector\Printify;

use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\POD\PrintifyProductPipeline;

/** Governed local source association. Never an operational mutation grant. */
final class NativeBusinessMutationAssociation
{
    public static function version(): string
    {
        return '1.0.0';
    }

    public static function source(ShopBinding $scope, int $actor, int $mapping, int $spec, string $plan): array|\WP_Error
    {
        $observed = self::observe($scope, $actor, $mapping, $spec, $plan);
        return is_wp_error($observed) ? $observed : $observed['source'];
    }

    private static function observe(ShopBinding $scope, int $actor, int $mapping, int $spec, string $plan): array|\WP_Error
    {
        $material = NativeBusinessMutationEvidence::sourceMaterial($scope, $actor, $mapping, $spec, $plan);
        if (is_wp_error($material)) {
            return $material;
        }
        $e = $material['evidence'];
        $payload = ['schema' => 'DF_NATIVE_MUTATION_ASSOCIATION_SOURCE_1','scope_fingerprint' => $scope->fingerprint(),
            'product_id' => $e['product_id'],'product_version_id' => $e['product_version_id'],
            'provider_mapping_id' => $mapping,'asset_spec_id' => $spec,'plan_hash' => $plan,
            'package_hash' => $e['package_hash'],'artwork_version_hash' => $e['artwork_version_hash'],
            'payload_fingerprint' => $e['payload_fingerprint'],'operation' => $e['operation'],
            'plan_policy_hash' => $e['plan_policy_hash'],'plan_policy_revision' => $e['plan_policy_revision'],
            'business_dependencies' => $material['business_dependencies'],'artwork_dependency_hash' => $material['artwork_dependency_hash']];
        // Actor-independent source identity permits a genuinely distinct reviewer.
        $payload['source_hash'] = NativePortContract::hash($payload);
        return ['source'=>$payload,'current_source_evidence_hash'=>$e['evidence_hash']];
    }

    public static function propose(ShopBinding $scope, int $actor, int $mapping, int $spec, string $plan, string $expectedSource, int $revision, int $effectiveAt, int $expiresAt, string $requestId): array|\WP_Error
    {
        $source = self::source($scope, $actor, $mapping, $spec, $plan);
        if (is_wp_error($source)) {
            return $source;
        }
        if (!hash_equals($source['source_hash'], $expectedSource)) {
            return NativeFailure::error('association_source_stale');
        }
        $result = ReviewedLocalEvidence::propose('policy', $scope->shopKey, self::key($scope, $source['product_id']), $source, $effectiveAt, $expiresAt, $requestId, $revision);
        if (is_wp_error($result)) {
            return $result;
        }
        $last = self::source($scope, $actor, $mapping, $spec, $plan);
        return is_wp_error($last) || $last !== $source ? NativeFailure::error('association_proposal_unknown') : $result;
    }

    public static function decide(ShopBinding $scope, int $actor, int $mapping, int $spec, string $plan, int $revision, string $proposalHash, string $decision, string $requestId): array|\WP_Error
    {
        $access = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_pod','manage_digiforge_products','manage_digiforge_settings']);
        if (is_wp_error($access)) {
            return $access;
        }
        // Revocation must remain possible after source invalidation. Resolve only the
        // immutable product identity from the current protected mapping, never trust it as approval.
        global $wpdb;
        $product = self::productIdentity($mapping);
        if (is_wp_error($product)) return $product;
        $key = self::key($scope, (int)$product);
        $before = ReviewedLocalEvidence::read('policy', $scope->shopKey, $key);
        if (is_wp_error($before) || !is_array($before)) {
            return NativeFailure::error('association_missing');
        }
        if ($decision === 'APPROVED') {
            $source = self::source($scope, $actor, $mapping, $spec, $plan);
            if (is_wp_error($source) || NativePortContract::hash($source) !== NativePortContract::hash($before['payload'])) {
                return NativeFailure::error('association_source_stale');
            }
        }
        $reviewDatabase = $wpdb;
        $wpdb->last_error = '';
        $connectionId = (string)$wpdb->get_var('SELECT CONNECTION_ID()');
        if ($wpdb->last_error !== '' || !preg_match('/^[1-9][0-9]*$/D', $connectionId)) {
            return NativeFailure::error('association_review_connection');
        }
        $condition = $decision !== 'APPROVED' ? null : static function () use ($scope, $actor, $mapping, $before, $revision, $proposalHash, $reviewDatabase, $connectionId): true|\WP_Error {
            global $wpdb;
            if ($wpdb !== $reviewDatabase || (string)$wpdb->get_var('SELECT CONNECTION_ID()') !== $connectionId || $wpdb->last_error !== '') {
                return NativeFailure::error('association_review_connection_changed');
            }
            $locked = NativeBusinessEvidence::lockCurrent($scope, $actor, $mapping, $before['payload']['business_dependencies']);
            if (is_wp_error($locked)) {
                return $locked;
            }
            $assignments = (new \DigiForge\Security\ShopAccessRepository())->read($scope->shopKey);
            if (is_wp_error($assignments)) {
                return $assignments;
            }
            $receipt = self::reviewReceipt($scope, $before['payload'], $before['author_id'], $actor, $revision + 1, $proposalHash, $assignments['revision']);
            return \DigiForge\Security\Logger::write('native_mutation_business_source_reviewed', $receipt, 'native_mutation_association', $proposalHash)
                ? true : NativeFailure::error('association_review_audit');
        };
        $result = ReviewedLocalEvidence::decide('policy', $scope->shopKey, $key, $revision, $proposalHash, $decision, $requestId, $condition);
        if (is_wp_error($result) || $decision !== 'APPROVED') {
            return $result;
        }
        $last = self::source($scope, $actor, $mapping, $spec, $plan);
        return is_wp_error($last) || NativePortContract::hash($last) !== NativePortContract::hash($before['payload']) ? NativeFailure::error('association_approval_unknown') : $result;
    }

    public static function read(ShopBinding $scope, int $actor, int $mapping, int $spec, string $plan): array|\WP_Error
    {
        $authority = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_pod','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_research']);
        if (is_wp_error($authority)) return $authority;
        // Provisional identity locates the review only. It cannot authenticate a source.
        $product = self::productIdentity($mapping);
        if (is_wp_error($product)) return $product;
        $key = self::key($scope, $product);
        $approval = ReviewedLocalEvidence::approved('policy', $scope->shopKey, $key);
        if (is_wp_error($approval)) return NativeFailure::error('association_current_approval_required');
        $frozen = $approval['payload'];
        if (($frozen['schema'] ?? null) !== 'DF_NATIVE_MUTATION_ASSOCIATION_SOURCE_1' || ($frozen['scope_fingerprint'] ?? null) !== $scope->fingerprint()
            || ($frozen['product_id'] ?? null) !== $product || ($frozen['provider_mapping_id'] ?? null) !== $mapping || ($frozen['asset_spec_id'] ?? null) !== $spec
            || ($frozen['plan_hash'] ?? null) !== $plan || !is_array($frozen['business_dependencies'] ?? null) || count($frozen['business_dependencies']) !== 15
            || !is_string($frozen['source_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $frozen['source_hash']) !== 1) return NativeFailure::error('association_source_identity');
        $producer = self::approvedReceipt($scope, $frozen, $approval);
        if (is_wp_error($producer)) return $producer;
        // The full original core resolver retains all source, expected-hash and
        // authority checks. Identical review/provenance snapshots bracket it.
        $observed = self::observe($scope, $actor, $mapping, $spec, $plan);
        if (is_wp_error($observed)) return $observed;
        $source = $observed['source'];
        if (NativePortContract::hash($source) !== NativePortContract::hash($frozen)) return NativeFailure::error('association_source_stale');
        $review = ReviewedLocalEvidence::approved('policy', $scope->shopKey, $key);
        if (is_wp_error($review) || $review !== $approval) return NativeFailure::error('association_changed');
        $lastProducer = self::approvedReceipt($scope, $source, $review);
        if (is_wp_error($lastProducer) || $lastProducer !== $producer) return NativeFailure::error('association_producer_changed');
        $out = ['schema'=>'DF_NATIVE_MUTATION_ASSOCIATION_1','version'=>self::version(),'scope_fingerprint'=>$scope->fingerprint(),
            'actor_id'=>$actor,'source'=>$source,'current_source_evidence_hash'=>$observed['current_source_evidence_hash'],
            'association_version'=>$approval['version'],'review_revision'=>$approval['revision'],
            'review_hash'=>$approval['proposal_hash'],'author_id'=>$approval['author_id'],'reviewer_id'=>$approval['reviewer_id'],
            'expires_at'=>$approval['expires_at'],'business_package_binding_approved'=>true,
            'business_dependency_cas_verified'=>true,'business_review_audit_id'=>$producer['id'],'business_review_receipt_hash'=>$producer['receipt_hash'],
            'source_approval_cas_verified'=>false,'native_product_version'=>null,'native_version_counters_verified'=>false,
            'companion_payload_equivalence_verified'=>false,'mutation_authorized'=>false,'dispatch_permitted'=>false,
            'independent_checkpoint_verified'=>false,'external_execution_authorized'=>false,'release_hold'=>true];
        $out['evidence_hash'] = NativePortContract::hash($out);
        return $out;
    }

    public static function uploadDescriptor(ShopBinding $scope, int $actor, int $mapping, int $spec, string $plan, string $expectedSource): array|\WP_Error
    {
        $association = self::read($scope, $actor, $mapping, $spec, $plan);
        if (is_wp_error($association)) {
            return $association;
        }
        if (!hash_equals($association['current_source_evidence_hash'], $expectedSource)) return NativeFailure::error('association_wire_source_stale');
        $source = $association['source'];
        $sealed = (new PrintifyProductPipeline())->plan($plan);
        if (is_wp_error($sealed)) {
            return $sealed;
        }
        $request = $sealed['request'];
        $body = $request['body'];
        if (($request['method'] ?? null) !== 'POST' || ($request['endpoint'] ?? null) !== '/v1/uploads/images.json' || !is_array($body) || count($body) !== 2 || !is_string($body['file_name'] ?? null) || !is_string($body['contents'] ?? null)) {
            return NativeFailure::error('association_wire_contract');
        }
        $wireHash = NativePortContract::hash(['method'=>$request['method'],'endpoint'=>$request['endpoint'],'body'=>$body]);
        if (!hash_equals($source['payload_fingerprint'], $wireHash)) return NativeFailure::error('association_wire_payload_mismatch');
        $bytes = base64_decode($body['contents'], true);
        if (!is_string($bytes) || $bytes === '') {
            return NativeFailure::error('association_asset_encoding');
        }
        $out = ['schema' => 'DF_NATIVE_IMAGE_UPLOAD_WIRE_1','version' => self::version(),'scope_fingerprint' => $scope->fingerprint(),
            'actor_id' => $actor,'source_evidence_hash' => $expectedSource,'plan_hash' => $plan,'package_hash' => $source['package_hash'],
            'asset_sha256' => hash('sha256', $bytes),'file_name' => $body['file_name'],'method' => 'POST','endpoint' => '/v1/uploads/images.json',
            'payload_fingerprint' => $source['payload_fingerprint']];
        $last = self::read($scope, $actor, $mapping, $spec, $plan);
        if (is_wp_error($last) || $last !== $association) {
            return NativeFailure::error('association_wire_changed');
        }
        $out['descriptor_hash'] = NativePortContract::hash($out);
        return $out;
    }

    private static function reviewReceipt(ShopBinding $scope, array $source, int $author, int $reviewer, int $revision, string $proposal, int $assignmentRevision): array
    {
        return ['schema'=>'DF_NATIVE_BUSINESS_ASSOCIATION_REVIEW_1','scope_fingerprint'=>$scope->fingerprint(),
            'source_hash'=>$source['source_hash'],'business_dependency_hash'=>NativePortContract::hash($source['business_dependencies']),
            'review_revision'=>$revision,'proposal_hash'=>$proposal,'author_id'=>$author,'reviewer_id'=>$reviewer,
            'shop_assignment_revision'=>$assignmentRevision,
            'business_dependencies_locked'=>true,'source_approval_cas_verified'=>false,'dispatch_permitted'=>false];
    }

    private static function approvedReceipt(ShopBinding $scope, array $source, array $approval): array|\WP_Error
    {
        global $wpdb;
        foreach ([$approval['author_id'], $approval['reviewer_id']] as $principal) {
            foreach (['manage_digiforge_connections','manage_digiforge_pod','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_research','manage_digiforge_settings'] as $capability) {
                $authority = \DigiForge\Security\ShopAccess::authorize($scope->shopKey, $capability, $principal);
                if (is_wp_error($authority)) {
                    return NativeFailure::error('association_principal_revoked');
                }
            }
        }
        $assignments = (new \DigiForge\Security\ShopAccessRepository())->read($scope->shopKey);
        if (is_wp_error($assignments)) {
            return $assignments;
        }
        $expected = self::reviewReceipt($scope, $source, $approval['author_id'], $approval['reviewer_id'], $approval['revision'], $approval['proposal_hash'], $assignments['revision']);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,actor_id,CASE WHEN OCTET_LENGTH(context)<=4096 THEN context ELSE NULL END AS context FROM '.\DigiForge\Database\Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s ORDER BY id LIMIT 2', 'native_mutation_business_source_reviewed', 'native_mutation_association', $approval['proposal_hash']), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows) || count($rows) !== 1 || !preg_match('/^[1-9][0-9]*$/D', (string)$rows[0]['id']) || (int)$rows[0]['actor_id'] !== $approval['reviewer_id']) {
            return NativeFailure::error('association_business_producer_required');
        }
        $receipt = json_decode((string)$rows[0]['context'], true);
        if (!is_array($receipt) || NativePortContract::hash($receipt) !== NativePortContract::hash($expected)) {
            return NativeFailure::error('association_business_receipt_mismatch');
        }
        return ['id'=>(int)$rows[0]['id'],'receipt_hash'=>NativePortContract::hash($expected)];
    }

    private static function productIdentity(int $mapping): int|\WP_Error
    {
        if ($mapping < 1) return NativeFailure::error('association_product_identity');
        global $wpdb;
        $wpdb->last_error = '';
        $product = $wpdb->get_var($wpdb->prepare('SELECT v.product_id FROM '.\DigiForge\Database\Tables::pod_mappings().' m JOIN '.\DigiForge\Database\Tables::product_versions().' v ON v.id=m.product_version_id WHERE m.id=%d', $mapping));
        if ($wpdb->last_error !== '' || !is_string($product) || preg_match('/^[1-9][0-9]*$/D', $product) !== 1 || (int)$product < 1 || (string)(int)$product !== $product) return NativeFailure::error('association_product_identity');
        return (int)$product;
    }

    private static function key(ShopBinding $scope, int $product): string
    {
        return 'native-mutation-source:' . NativePortContract::hash([$scope->shopKey,$scope->integrationId,$scope->providerShopId,$scope->environment,$product]);
    }
}
