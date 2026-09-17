<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;
use WP_Error;

/** Writes business-active POD ownership only after registry resolution. */
final class BusinessScopeRepository
{
    public function createMapping(array $input, ?string $idempotencyKey = null): array|WP_Error
    {
        global $wpdb;
        $productVersionId = absint($input['product_version_id'] ?? 0);
        $providerMappingId = absint($input['provider_mapping_id'] ?? 0);
        if ($productVersionId < 1 || $providerMappingId < 1) {
            return new WP_Error('digiforge_scope_validation', 'product_version_id and provider_mapping_id are required.', ['status' => 400]);
        }
        $key = $idempotencyKey === null ? null : sanitize_text_field($idempotencyKey);
        if ($key === '') $key = null;

        // Exact replay is immutable historical ownership, not a new activation.
        // Compare submitted references against the stored registry identities
        // before requiring the scope to remain ACTIVE.
        if ($key !== null) {
            $existing = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . Tables::pod_business_mappings() . ' WHERE idempotency_key=%s LIMIT 1', $key
            ), ARRAY_A);
            if (is_array($existing)) {
                $businessRef = sanitize_key((string)($input['business_id'] ?? ''));
                $storeRef = sanitize_key((string)($input['store_id'] ?? ''));
                $program = strtoupper(trim((string)($input['product_program'] ?? '')));
                $business = $wpdb->get_row($wpdb->prepare('SELECT id,business_key FROM '.Tables::businesses().' WHERE id=%d LIMIT 1',(int)$existing['business_id']),ARRAY_A);
                $store = $wpdb->get_row($wpdb->prepare('SELECT id,store_key FROM '.Tables::stores().' WHERE id=%d LIMIT 1',(int)$existing['store_id']),ARRAY_A);
                $programRow = $wpdb->get_row($wpdb->prepare('SELECT id,program_key FROM '.Tables::product_programs().' WHERE id=%d LIMIT 1',(int)$existing['product_program_id']),ARRAY_A);
                $businessMatches = is_array($business) && ($businessRef === (string)$business['id'] || $businessRef === sanitize_key((string)$business['business_key']));
                $storeMatches = is_array($store) && ($storeRef === (string)$store['id'] || $storeRef === sanitize_key((string)$store['store_key']));
                $same = $businessMatches && $storeMatches && is_array($programRow) && $program === strtoupper((string)$programRow['program_key'])
                    && (int)$existing['product_version_id'] === $productVersionId && (int)$existing['provider_mapping_id'] === $providerMappingId;
                return $same ? $existing : new WP_Error('digiforge_idempotency_conflict','Idempotency key is already bound to a different business scope or mapping.',['status'=>409]);
            }
        }

        $wpdb->query('START TRANSACTION');
        try {
            // Resolve inside the transaction, then lock and revalidate both IDs
            // and submitted keys so key reassignment cannot cross the boundary.
            try { $scope = BusinessScope::resolveConfigured($input); }
            catch (\InvalidArgumentException $e) { $wpdb->query('ROLLBACK'); return new WP_Error('digiforge_scope_validation',$e->getMessage(),['status'=>409]); }
            $businessRef=sanitize_key((string)$input['business_id']); $storeRef=sanitize_key((string)$input['store_id']);
            $business=$wpdb->get_row($wpdb->prepare('SELECT id,business_key FROM '.Tables::businesses().' WHERE id=%d AND status=%s FOR UPDATE',$scope['business_id'],'ACTIVE'),ARRAY_A);
            $store=$wpdb->get_row($wpdb->prepare('SELECT id,store_key FROM '.Tables::stores().' WHERE id=%d AND business_id=%d AND status=%s FOR UPDATE',$scope['store_id'],$scope['business_id'],'ACTIVE'),ARRAY_A);
            $program=$wpdb->get_row($wpdb->prepare('SELECT id,program_key FROM '.Tables::product_programs().' WHERE id=%d AND business_id=%d AND store_id=%d AND status=%s FOR UPDATE',$scope['product_program_id'],$scope['business_id'],$scope['store_id'],'ACTIVE'),ARRAY_A);
            $businessMatches=is_array($business)&&($businessRef===(string)$business['id']||$businessRef===sanitize_key((string)$business['business_key']));
            $storeMatches=is_array($store)&&($storeRef===(string)$store['id']||$storeRef===sanitize_key((string)$store['store_key']));
            if(!$businessMatches||!$storeMatches||!is_array($program)||strtoupper((string)$program['program_key'])!==$scope['product_program']){ $wpdb->query('ROLLBACK'); return new WP_Error('digiforge_scope_changed','Business/store/product-program keys changed during ownership resolution.',['status'=>409]); }
            if(sanitize_key((string)$business['business_key'])===BusinessScope::DIGICRAFTIFY_GOODS && $scope['product_program']!==BusinessScope::PERSONALIZED_POD){$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_validation','DigiCraftifyGoods is restricted to PERSONALIZED_POD',['status'=>409]);}

            $provider=$wpdb->get_row($wpdb->prepare('SELECT product_version_id FROM '.Tables::pod_mappings().' WHERE id=%d FOR UPDATE',$providerMappingId),ARRAY_A);
            if(!is_array($provider)||(int)$provider['product_version_id']!==$productVersionId){$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_relationship','Provider mapping must exist and match the product version.',['status'=>409]);}
            $owner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE product_version_id=%d AND provider_mapping_id=%d LIMIT 1 FOR UPDATE',$productVersionId,$providerMappingId),ARRAY_A);
            if(is_array($owner)){$same=(int)$owner['business_id']===$scope['business_id']&&(int)$owner['store_id']===$scope['store_id']&&(int)$owner['product_program_id']===$scope['product_program_id'];$wpdb->query('ROLLBACK');return new WP_Error($same?'digiforge_scope_mapping_exists':'digiforge_scope_ownership_conflict',$same?'Scoped POD mapping already exists.':'Product/provider mapping is owned by a different business scope.',['status'=>409]);}
            $now=current_time('mysql',true);
            $inserted=$wpdb->insert(Tables::pod_business_mappings(),['business_id'=>$scope['business_id'],'store_id'=>$scope['store_id'],'product_program_id'=>$scope['product_program_id'],'product_version_id'=>$productVersionId,'provider_mapping_id'=>$providerMappingId,'state'=>'DRAFT','idempotency_key'=>$key,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
            if($inserted!==1){$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_create_failed','Unable to create scoped POD mapping.',['status'=>500]);}
            $created=(array)$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE id=%d',(int)$wpdb->insert_id),ARRAY_A);$wpdb->query('COMMIT');return $created;
        } catch (\Throwable) {$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_create_failed','Unable to create scoped POD mapping.',['status'=>500]);}
    }
}
