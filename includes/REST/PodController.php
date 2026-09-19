<?php

declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\POD\BusinessScopeRepository;
use DigiForge\POD\Repository;
use DigiForge\Queue\Idempotency;

final class PodController
{
    private const NS='digiforge/v1';private const MAX_BODY_BYTES=65536;
    public function register(): void
    {
        add_action('rest_api_init',function():void{
            register_rest_route(self::NS,'/pod/(?P<entity>catalog|mappings|print_areas|personalization|bindings|intents|costs)',['methods'=>'GET','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'list']]);
            register_rest_route(self::NS,'/pod/catalog',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createCatalog']]);
            register_rest_route(self::NS,'/pod/mappings',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createMapping']]);
            register_rest_route(self::NS,'/pod/print-areas',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createPrintArea']]);
            register_rest_route(self::NS,'/pod/personalization',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createPersonalization']]);
            register_rest_route(self::NS,'/pod/bindings',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createBinding']]);
            register_rest_route(self::NS,'/pod/intents',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createIntent']]);
            register_rest_route(self::NS,'/pod/costs',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createCost']]);
            register_rest_route(self::NS,'/pod/(?P<entity>catalog|mapping|print_area|personalization|intent|cost)/(?P<id>\d+)/state',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'transition']]);
            register_rest_route(self::NS,'/pod/mappings/(?P<id>\d+)/readiness',['methods'=>'GET','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'readiness']]);
        });
    }
    public function canManage(): bool{return current_user_can('manage_digiforge_pod');}
    public function list(\WP_REST_Request $r): \WP_REST_Response
    {
        $result=(new Repository())->list((string)$r['entity'],max(1,(int)($r->get_param('page')?:1)),min(100,max(1,(int)($r->get_param('per_page')?:20))));
        $response=new \WP_REST_Response($result['items']);$response->header('X-WP-Total',(string)$result['pagination']['total_items']);$response->header('X-WP-TotalPages',(string)$result['pagination']['total_pages']);return $response;
    }
    public function createCatalog(\WP_REST_Request $r): mixed{return $this->mutate($r,'pod_catalog_create',fn()=>(new Repository())->createCatalog((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function createMapping(\WP_REST_Request $r): mixed
    {
        $key=$this->rawKey($r);if($key===null)return new \WP_Error('missing_idempotency_key',__('Idempotency-Key header is required.','digiforge'),['status'=>400]);
        $input=(array)$r->get_json_params();$scopedRepo=new BusinessScopeRepository();global $wpdb;
        $wpdb->query('START TRANSACTION');
        try{
            // Provider mapping replay happens first because its stable database ID is part of
            // the immutable business-scope fingerprint. Both writes share this transaction.
            $mapping=(new Repository())->createMapping($input,$key);if(is_wp_error($mapping)){$wpdb->query('ROLLBACK');return $mapping;}
            $input['provider_mapping_id']=(int)($mapping['id']??0);
            $scoped=$scopedRepo->createMapping($input,$key,false);if(is_wp_error($scoped)){$wpdb->query('ROLLBACK');return $scoped;}
            $wpdb->query('COMMIT');
            $status=!empty($mapping['idempotent_replay'])?200:201;
            return new \WP_REST_Response(['provider_mapping'=>$mapping,'business_mapping'=>$scoped],$status);
        }catch(\Throwable){$wpdb->query('ROLLBACK');return new \WP_Error('digiforge_mapping_atomicity','Provider mapping and business ownership could not be created atomically.',['status'=>500]);}
    }
    public function createPrintArea(\WP_REST_Request $r): mixed{return $this->mutate($r,'pod_print_area_create',fn()=>(new Repository())->createPrintArea((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function createPersonalization(\WP_REST_Request $r): mixed{return $this->mutate($r,'pod_personalization_create',fn()=>(new Repository())->createPersonalizationSchema((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function createBinding(\WP_REST_Request $r): mixed{return $this->mutate($r,'pod_binding_create',fn()=>(new Repository())->createBinding((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function createIntent(\WP_REST_Request $r): mixed{return $this->mutate($r,'pod_intent_create',fn()=>(new Repository())->createIntent((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function createCost(\WP_REST_Request $r): mixed{return $this->mutate($r,'pod_cost_create',fn()=>(new Repository())->createCostSnapshot((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function transition(\WP_REST_Request $r): mixed{$p=(array)$r->get_json_params();$state=(string)($p['state']??'');return $this->mutate($r,'pod_state_'.sanitize_key((string)$r['entity']).'_'.(int)$r['id'].'_'.sanitize_key($state),fn()=>(new Repository())->transition((string)$r['entity'],(int)$r['id'],$state));}
    public function readiness(\WP_REST_Request $r): mixed{$result=(new Repository())->readiness((int)$r['id']);return is_wp_error($result)?$result:new \WP_REST_Response($result,200);}
    private function rawKey(\WP_REST_Request $r): ?string{$k=trim((string)$r->get_header('Idempotency-Key'));return $k===''?null:$k;}
    private function mutate(\WP_REST_Request $r,string $operation,callable $callback,int $success=200): mixed
    {
        if(strlen((string)$r->get_body())>self::MAX_BODY_BYTES)return new \WP_Error('payload_too_large',__('JSON body exceeds 64 KiB.','digiforge'),['status'=>413]);
        $header=trim((string)$r->get_header('Idempotency-Key'));if($header==='')return new \WP_Error('missing_idempotency_key',__('Idempotency-Key header is required.','digiforge'),['status'=>400]);
        $storage=hash('sha256',$operation.'|'.$header);$guard=new Idempotency();
        if(!$guard->reserve($storage,$operation)){
            $state=$guard->status($storage);
            if($state==='SUCCESS') return new \WP_Error('idempotency_replay',__('This POD mutation already completed successfully; replay the persisted resource instead of executing it again.','digiforge'),['status'=>409]);
            return new \WP_Error('idempotency_conflict',__('This POD mutation is already pending.','digiforge'),['status'=>409]);
        }
        try{$result=$callback();}catch(\Throwable){$guard->release($storage);return new \WP_Error('pod_mutation_failed',__('POD mutation failed.','digiforge'),['status'=>500]);}
        if(is_wp_error($result)){$guard->release($storage);return $result;}$encoded=wp_json_encode($result);if(!$guard->complete($storage,is_string($encoded)?$encoded:''))return new \WP_Error('idempotency_finalize_failed',__('Mutation completed but idempotency state could not be finalized.','digiforge'),['status'=>500]);return new \WP_REST_Response($result,$success);
    }
}
