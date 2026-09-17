<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;
use WP_Error;

final class BusinessScopeRepository
{
    public function replay(array $input,?string $idempotencyKey): array|WP_Error|null
    {
        global $wpdb;$key=$idempotencyKey===null?null:sanitize_text_field($idempotencyKey);if($key===null||$key==='')return null;
        $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE idempotency_key=%s LIMIT 1',$key),ARRAY_A);if(!is_array($existing))return null;
        $fingerprint=$this->requestFingerprint($input);if(is_wp_error($fingerprint))return $fingerprint;
        return hash_equals((string)($existing['request_fingerprint']??''),$fingerprint)?$existing:new WP_Error('digiforge_idempotency_conflict','Idempotency key is already bound to a different business scope or mapping.',['status'=>409]);
    }

    /** Fail closed when the mapping no longer has a currently ACTIVE, relationally valid owner. */
    public function assertActiveOwnershipForMapping(int $mappingId): array|WP_Error
    {
        global $wpdb;if($mappingId<1)return new WP_Error('digiforge_scope_validation','Valid provider mapping is required.',['status'=>400]);
        $sql='SELECT m.*,b.business_key,s.store_key,p.program_key FROM '.Tables::pod_business_mappings().' m INNER JOIN '.Tables::businesses().' b ON b.id=m.business_id INNER JOIN '.Tables::stores().' s ON s.id=m.store_id AND s.business_id=b.id INNER JOIN '.Tables::product_programs().' p ON p.id=m.product_program_id AND p.business_id=b.id AND p.store_id=s.id WHERE m.provider_mapping_id=%d AND b.status=%s AND s.status=%s AND p.status=%s LIMIT 1';
        $row=$wpdb->get_row($wpdb->prepare($sql,$mappingId,'ACTIVE','ACTIVE','ACTIVE'),ARRAY_A);
        if(!is_array($row))return new WP_Error('digiforge_scope_inactive','Provider mapping has no active business/store/product-program ownership.',['status'=>409]);
        if(sanitize_key((string)$row['business_key'])===BusinessScope::DIGICRAFTIFY_GOODS&&strtoupper((string)$row['program_key'])!==BusinessScope::PERSONALIZED_POD)return new WP_Error('digiforge_scope_validation','DigiCraftifyGoods is restricted to PERSONALIZED_POD',['status'=>409]);
        return $row;
    }

    public function createMapping(array $input,?string $idempotencyKey=null,bool $manageTransaction=true): array|WP_Error
    {
        global $wpdb;$replay=$this->replay($input,$idempotencyKey);if($replay!==null)return $replay;
        $productVersionId=absint($input['product_version_id']??0);$providerMappingId=absint($input['provider_mapping_id']??0);if($productVersionId<1||$providerMappingId<1)return new WP_Error('digiforge_scope_validation','product_version_id and provider_mapping_id are required.',['status'=>400]);
        $key=$idempotencyKey===null?null:sanitize_text_field($idempotencyKey);if($key==='')$key=null;$fingerprint=$this->requestFingerprint($input);if(is_wp_error($fingerprint))return $fingerprint;
        if($manageTransaction)$wpdb->query('START TRANSACTION');
        try{
            try{$scope=BusinessScope::resolveConfigured($input);}catch(\InvalidArgumentException $e){if($manageTransaction)$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_validation',$e->getMessage(),['status'=>409]);}
            $businessRef=sanitize_key((string)$input['business_id']);$storeRef=sanitize_key((string)$input['store_id']);
            $business=$wpdb->get_row($wpdb->prepare('SELECT id,business_key FROM '.Tables::businesses().' WHERE id=%d AND status=%s FOR UPDATE',$scope['business_id'],'ACTIVE'),ARRAY_A);$store=$wpdb->get_row($wpdb->prepare('SELECT id,store_key FROM '.Tables::stores().' WHERE id=%d AND business_id=%d AND status=%s FOR UPDATE',$scope['store_id'],$scope['business_id'],'ACTIVE'),ARRAY_A);$program=$wpdb->get_row($wpdb->prepare('SELECT id,program_key FROM '.Tables::product_programs().' WHERE id=%d AND business_id=%d AND store_id=%d AND status=%s FOR UPDATE',$scope['product_program_id'],$scope['business_id'],$scope['store_id'],'ACTIVE'),ARRAY_A);
            $businessMatches=is_array($business)&&($businessRef===(string)$business['id']||$businessRef===sanitize_key((string)$business['business_key']));$storeMatches=is_array($store)&&($storeRef===(string)$store['id']||$storeRef===sanitize_key((string)$store['store_key']));if(!$businessMatches||!$storeMatches||!is_array($program)||strtoupper((string)$program['program_key'])!==$scope['product_program']){if($manageTransaction)$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_changed','Business/store/product-program keys changed during ownership resolution.',['status'=>409]);}
            if(sanitize_key((string)$business['business_key'])===BusinessScope::DIGICRAFTIFY_GOODS&&$scope['product_program']!==BusinessScope::PERSONALIZED_POD){if($manageTransaction)$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_validation','DigiCraftifyGoods is restricted to PERSONALIZED_POD',['status'=>409]);}
            $provider=$wpdb->get_row($wpdb->prepare('SELECT product_version_id FROM '.Tables::pod_mappings().' WHERE id=%d FOR UPDATE',$providerMappingId),ARRAY_A);if(!is_array($provider)||(int)$provider['product_version_id']!==$productVersionId){if($manageTransaction)$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_relationship','Provider mapping must exist and match the product version.',['status'=>409]);}
            $owner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE product_version_id=%d AND provider_mapping_id=%d LIMIT 1 FOR UPDATE',$productVersionId,$providerMappingId),ARRAY_A);if(is_array($owner)){if($manageTransaction)$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_ownership_conflict','Product/provider mapping already has business ownership.',['status'=>409]);}
            $now=current_time('mysql',true);$inserted=$wpdb->insert(Tables::pod_business_mappings(),['business_id'=>$scope['business_id'],'store_id'=>$scope['store_id'],'product_program_id'=>$scope['product_program_id'],'product_version_id'=>$productVersionId,'provider_mapping_id'=>$providerMappingId,'state'=>'DRAFT','request_fingerprint'=>$fingerprint,'idempotency_key'=>$key,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);if($inserted!==1){if($manageTransaction)$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_create_failed','Unable to create scoped POD mapping.',['status'=>500]);}
            $created=(array)$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE id=%d',(int)$wpdb->insert_id),ARRAY_A);if($manageTransaction)$wpdb->query('COMMIT');return $created;
        }catch(\Throwable){if($manageTransaction)$wpdb->query('ROLLBACK');return new WP_Error('digiforge_scope_create_failed','Unable to create scoped POD mapping.',['status'=>500]);}
    }

    private function requestFingerprint(array $input): string|WP_Error
    {
        $productVersionId=absint($input['product_version_id']??0);$providerMappingId=absint($input['provider_mapping_id']??0);$business=sanitize_key((string)($input['business_id']??''));$store=sanitize_key((string)($input['store_id']??''));$program=strtoupper(trim((string)($input['product_program']??'')));
        if($productVersionId<1||$providerMappingId<1||$business===''||$store===''||$program==='')return new WP_Error('digiforge_scope_validation','Complete business/store/program and mapping identity is required.',['status'=>400]);
        $payload=wp_json_encode(['business_ref'=>$business,'store_ref'=>$store,'product_program'=>$program,'product_version_id'=>$productVersionId,'provider_mapping_id'=>$providerMappingId]);if(!is_string($payload))return new WP_Error('digiforge_scope_validation','Unable to fingerprint scope request.',['status'=>400]);return hash('sha256',$payload);
    }
}
