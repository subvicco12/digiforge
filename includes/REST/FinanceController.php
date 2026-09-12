<?php

declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\Finance\Repository;
use DigiForge\Finance\Validator;
use DigiForge\Queue\Idempotency;

final class FinanceController
{
    private const NS = 'digiforge/v1';

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/finance/(?P<entity>ledger|fx|tax|periods|analytics|alerts|intents)', [
                'methods'=>'GET','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'list'],
            ]);
            register_rest_route(self::NS, '/finance/ledger', [
                'methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createLedger'],
            ]);
            register_rest_route(self::NS, '/finance/fx', [
                'methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createFx'],
            ]);
            register_rest_route(self::NS, '/finance/tax', [
                'methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createTax'],
            ]);
            register_rest_route(self::NS, '/finance/periods/calculate', [
                'methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'calculatePeriod'],
            ]);
            register_rest_route(self::NS, '/finance/intents', [
                'methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createIntent'],
            ]);
            register_rest_route(self::NS, '/finance/(?P<entity>period|alert|intent)/(?P<id>\d+)/state', [
                'methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'transition'],
            ]);
        });
    }

    public function canManage(): bool { return current_user_can('manage_digiforge_finance'); }

    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        $result=(new Repository())->list((string)$request['entity'],max(1,(int)($request->get_param('page')?:1)),min(100,max(1,(int)($request->get_param('per_page')?:20))));
        $response=new \WP_REST_Response($result['items']);
        $response->header('X-WP-Total',(string)$result['pagination']['total_items']);
        $response->header('X-WP-TotalPages',(string)$result['pagination']['total_pages']);
        return $response;
    }

    public function createLedger(\WP_REST_Request $request): mixed
    { return $this->mutate($request,'finance_ledger_create',fn()=>(new Repository())->createLedger((array)$request->get_json_params(),$this->key($request)),201); }

    public function createFx(\WP_REST_Request $request): mixed
    { return $this->mutate($request,'finance_fx_create',fn()=>(new Repository())->createFx((array)$request->get_json_params(),$this->key($request)),201); }

    public function createTax(\WP_REST_Request $request): mixed
    { return $this->mutate($request,'finance_tax_create',fn()=>(new Repository())->createTaxClassification((array)$request->get_json_params(),$this->key($request)),201); }

    public function calculatePeriod(\WP_REST_Request $request): mixed
    { return $this->mutate($request,'finance_period_calculate',fn()=>(new Repository())->calculatePeriod((array)$request->get_json_params(),$this->key($request)),201); }

    public function createIntent(\WP_REST_Request $request): mixed
    { return $this->mutate($request,'finance_intent_create',fn()=>(new Repository())->createIntent((array)$request->get_json_params(),$this->key($request)),201); }

    public function transition(\WP_REST_Request $request): mixed
    {
        $params=(array)$request->get_json_params(); $state=(string)($params['state']??'');
        $op='finance_state_'.sanitize_key((string)$request['entity']).'_'.(int)$request['id'].'_'.sanitize_key($state);
        return $this->mutate($request,$op,fn()=>(new Repository())->transition((string)$request['entity'],(int)$request['id'],$state));
    }

    private function key(\WP_REST_Request $request): ?string
    { $key=trim((string)$request->get_header('Idempotency-Key')); return $key===''?null:$key; }

    private function mutate(\WP_REST_Request $request,string $operation,callable $callback,int $success=200): mixed
    {
        if(strlen((string)$request->get_body()) > Validator::MAX_BODY_BYTES) {
            return new \WP_Error('payload_too_large',__('JSON body exceeds 64 KiB.','digiforge'),['status'=>413]);
        }
        $header=trim((string)$request->get_header('Idempotency-Key'));
        if($header==='') return new \WP_Error('missing_idempotency_key',__('Idempotency-Key header is required.','digiforge'),['status'=>400]);
        $storage=hash('sha256',$operation.'|'.$header); $guard=new Idempotency();
        if(!$guard->reserve($storage,$operation)) return new \WP_Error('idempotency_conflict',__('This finance mutation has already been submitted.','digiforge'),['status'=>409]);
        try{$result=$callback();}catch(\Throwable){$guard->release($storage);return new \WP_Error('finance_mutation_failed',__('Finance mutation failed.','digiforge'),['status'=>500]);}
        if(is_wp_error($result)){$guard->release($storage);return $result;}
        $encoded=wp_json_encode($result);
        if(!$guard->complete($storage,is_string($encoded)?$encoded:'')) return new \WP_Error('idempotency_finalize_failed',__('Mutation completed but idempotency state could not be finalized.','digiforge'),['status'=>500]);
        return new \WP_REST_Response($result,$success);
    }
}
