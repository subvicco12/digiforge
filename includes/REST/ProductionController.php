<?php

declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\Production\Repository;
use DigiForge\Queue\Idempotency;

final class ProductionController
{
    private const NS = 'digiforge/v1';
    private const MAX_BODY_BYTES = 65536;

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/production/(?P<entity>specs|plans|intents|revisions|qa|bundles)', ['methods'=>'GET','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'list']]);
            register_rest_route(self::NS, '/production/specs', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createSpec']]);
            register_rest_route(self::NS, '/production/plans', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createPlan']]);
            register_rest_route(self::NS, '/production/plans/(?P<id>\d+)/assets', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'linkAsset']]);
            register_rest_route(self::NS, '/production/intents', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createIntent']]);
            register_rest_route(self::NS, '/production/revisions', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createRevision']]);
            register_rest_route(self::NS, '/production/qa', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createQa']]);
            register_rest_route(self::NS, '/production/bundles', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createBundle']]);
            register_rest_route(self::NS, '/production/bundles/(?P<id>\d+)/validate', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'validateBundle']]);
            register_rest_route(self::NS, '/production/(?P<entity>spec|plan|intent|revision|bundle)/(?P<id>\d+)/state', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'transition']]);
        });
    }

    public function canManage(): bool { return current_user_can('manage_digiforge_production'); }

    public function list(\WP_REST_Request $r): \WP_REST_Response
    {
        $result=(new Repository())->list((string)$r['entity'],max(1,(int)($r->get_param('page')?:1)),min(100,max(1,(int)($r->get_param('per_page')?:20))));
        $response=new \WP_REST_Response($result['items']);
        $response->header('X-WP-Total',(string)$result['pagination']['total_items']);
        $response->header('X-WP-TotalPages',(string)$result['pagination']['total_pages']);
        return $response;
    }

    public function createSpec(\WP_REST_Request $r): mixed { return $this->mutate($r,'production_spec_create',fn()=>(new Repository())->createSpec((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function createPlan(\WP_REST_Request $r): mixed { return $this->mutate($r,'production_plan_create',fn()=>(new Repository())->createPlan((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function createIntent(\WP_REST_Request $r): mixed { return $this->mutate($r,'production_intent_create',fn()=>(new Repository())->createIntent((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function createRevision(\WP_REST_Request $r): mixed { return $this->mutate($r,'production_revision_create',fn()=>(new Repository())->addRevision((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function createQa(\WP_REST_Request $r): mixed { return $this->mutate($r,'production_qa_create',fn()=>(new Repository())->addQa((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function createBundle(\WP_REST_Request $r): mixed { return $this->mutate($r,'production_bundle_create',fn()=>(new Repository())->createBundle((array)$r->get_json_params(),$this->rawKey($r)),201); }

    public function linkAsset(\WP_REST_Request $r): mixed
    {
        $p=(array)$r->get_json_params();
        return $this->mutate($r,'production_plan_asset_'.(int)$r['id'].'_'.absint($p['asset_spec_id']??0),fn()=>(new Repository())->linkAsset((int)$r['id'],absint($p['asset_spec_id']??0),(bool)($p['required']??true),absint($p['sequence_no']??0)));
    }

    public function validateBundle(\WP_REST_Request $r): mixed { return $this->mutate($r,'production_bundle_validate_'.(int)$r['id'],fn()=>(new Repository())->validateBundle((int)$r['id'])); }

    public function transition(\WP_REST_Request $r): mixed
    {
        $p=(array)$r->get_json_params();
        $state=(string)($p['state']??'');
        return $this->mutate($r,'production_state_'.sanitize_key((string)$r['entity']).'_'.(int)$r['id'].'_'.sanitize_key($state),fn()=>(new Repository())->transition((string)$r['entity'],(int)$r['id'],$state));
    }

    private function rawKey(\WP_REST_Request $r): ?string { $k=trim((string)$r->get_header('Idempotency-Key')); return $k===''?null:$k; }

    private function mutate(\WP_REST_Request $r,string $operation,callable $callback,int $success=200): mixed
    {
        if(strlen((string)$r->get_body())>self::MAX_BODY_BYTES) return new \WP_Error('payload_too_large',__('JSON body exceeds 64 KiB.','digiforge'),['status'=>413]);
        $header=trim((string)$r->get_header('Idempotency-Key'));
        if($header==='') return new \WP_Error('missing_idempotency_key',__('Idempotency-Key header is required.','digiforge'),['status'=>400]);
        $storage=hash('sha256',$operation.'|'.$header);$guard=new Idempotency();
        if(!$guard->reserve($storage,$operation)) return new \WP_Error('idempotency_conflict',__('This production mutation has already been submitted.','digiforge'),['status'=>409]);
        try{$result=$callback();}catch(\Throwable){$guard->release($storage);return new \WP_Error('production_mutation_failed',__('Production mutation failed.','digiforge'),['status'=>500]);}
        if(is_wp_error($result)){$guard->release($storage);return $result;}
        $encoded=wp_json_encode($result);if(!$guard->complete($storage,is_string($encoded)?$encoded:''))return new \WP_Error('idempotency_finalize_failed',__('Mutation completed but idempotency state could not be finalized.','digiforge'),['status'=>500]);
        return new \WP_REST_Response($result,$success);
    }
}
