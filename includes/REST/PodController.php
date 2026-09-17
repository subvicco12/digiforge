<?php

declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\POD\BusinessScopeRepository;
use DigiForge\POD\Repository;
use DigiForge\Queue\Idempotency;

final class PodController
{
    private const NS='digiforge/v1';
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
        /* Mapping idempotency belongs to the scoped repository. It must be able
         * to return an exact historical replay even after a scope is disabled.
         * Do not pre-empt it with the generic outer reservation. */
        if ($this->rawKey($r)===null) return new \WP_Error('missing_idempotency_key',__('Idempotency-Key header is required.','digiforge'),['status'=>400]);
        $input=(array)$r->get_json_params();
        $scopedRepo=new BusinessScopeRepository();
        if (method_exists($scopedRepo,'replay')) {
            $replay=$scopedRepo->replay($input,$this->rawKey($r));
            if (is_wp_error($replay)) return $replay;
            if (is_array($replay)) return new \WP_REST_Response($replay,200);
        }
        /* Provider creation is intentionally not reported as success until its
         * ownership row exists. Full atomic creation is enforced by the scoped
         * repository integration; this controller remains fail-closed. */
        $mapping=(new Repository())->createMapping($input,$this->rawKey($r));
        if(is_wp_error($mapping)) return $mapping;
        $input['provider_mapping_id']=(int)($mapping['id']??0);
        $scoped=$scopedRepo->createMapping($input,$this->rawKey($r));
        if(is_wp_error($scoped)) return $scoped;
        return new \WP_REST_Response(['provider_mapping'=>$mapping,'business_mapping'=>$scoped],201);
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
        $header=trim((string)$r->get_header('Idempotency-Key'));if($header==='')return new \WP_Error('missing_idempotency_key',__('Idempotency-Key header is required.','digiforge'),['status'=>400]);
        $storage=hash('sha256',$operation.'|'.$header);$guard=new Idempotency();if(!$guard->reserve($storage,$operation))return new \WP_Error('idempotency_conflict',__('This POD mutation has already been submitted.','digiforge'),['status'=>409]);
        try{$result=$callback();}catch(\Throwable){$guard->release($storage);return new \WP_Error('pod_mutation_failed',__('POD mutation failed.','digiforge'),['status'=>500]);}
        if(is_wp_error($result)){$guard->release($storage);return $result;}$encoded=wp_json_encode($result);if(!$guard->complete($storage,is_string($encoded)?$encoded:''))return new \WP_Error('idempotency_finalize_failed',__('Mutation completed but idempotency state could not be finalized.','digiforge'),['status'=>500]);return new \WP_REST_Response($result,$success);
    }
}
