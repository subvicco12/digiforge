<?php

declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\Listings\Repository;
use DigiForge\Listings\Validator;
use DigiForge\Queue\Idempotency;

final class ListingController
{
    private const NS = 'digiforge/v1';

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/listings/(?P<entity>listings|seo|media|pod|packages|intents)', ['methods'=>'GET','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'list']]);
            register_rest_route(self::NS, '/listings', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createListing']]);
            register_rest_route(self::NS, '/listings/seo', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'setSeo']]);
            register_rest_route(self::NS, '/listings/media', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'bindMedia']]);
            register_rest_route(self::NS, '/listings/pod', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'bindPod']]);
            register_rest_route(self::NS, '/listings/packages', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createPackage']]);
            register_rest_route(self::NS, '/listings/intents', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createIntent']]);
            register_rest_route(self::NS, '/listings/(?P<entity>listing|intent)/(?P<id>\d+)/state', ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'transition']]);
            register_rest_route(self::NS, '/listings/(?P<id>\d+)/readiness', ['methods'=>'GET','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'readiness']]);
        });
    }

    public function canManage(): bool { return current_user_can('manage_digiforge_listings'); }

    public function list(\WP_REST_Request $r): \WP_REST_Response
    {
        $result=(new Repository())->list((string)$r['entity'],max(1,(int)($r->get_param('page')?:1)),min(100,max(1,(int)($r->get_param('per_page')?:20))));
        $response=new \WP_REST_Response($result['items']);
        $response->header('X-WP-Total',(string)$result['pagination']['total_items']);
        $response->header('X-WP-TotalPages',(string)$result['pagination']['total_pages']);
        return $response;
    }

    public function createListing(\WP_REST_Request $r): mixed { return $this->mutate($r,'listing_create',fn()=>(new Repository())->createListing((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function setSeo(\WP_REST_Request $r): mixed { return $this->mutate($r,'listing_seo_set',fn()=>(new Repository())->setSeo((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function bindMedia(\WP_REST_Request $r): mixed { return $this->mutate($r,'listing_media_bind',fn()=>(new Repository())->bindMedia((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function bindPod(\WP_REST_Request $r): mixed { return $this->mutate($r,'listing_pod_bind',fn()=>(new Repository())->bindPod((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function createPackage(\WP_REST_Request $r): mixed { return $this->mutate($r,'listing_package_create',fn()=>(new Repository())->createDraftPackage((array)$r->get_json_params(),$this->rawKey($r)),201); }
    public function createIntent(\WP_REST_Request $r): mixed { return $this->mutate($r,'etsy_intent_create',fn()=>(new Repository())->createIntent((array)$r->get_json_params(),$this->rawKey($r)),201); }

    public function transition(\WP_REST_Request $r): mixed
    {
        $p=(array)$r->get_json_params(); $state=(string)($p['state']??'');
        return $this->mutate($r,'listing_state_'.sanitize_key((string)$r['entity']).'_'.(int)$r['id'].'_'.sanitize_key($state),fn()=>(new Repository())->transition((string)$r['entity'],(int)$r['id'],$state));
    }

    public function readiness(\WP_REST_Request $r): mixed
    {
        $result=(new Repository())->readiness((int)$r['id']);
        return is_wp_error($result)?$result:new \WP_REST_Response($result,200);
    }

    private function rawKey(\WP_REST_Request $r): ?string
    {
        $k = trim((string) $r->get_header('Idempotency-Key'));
        if ($k === '') {
            $params = (array) $r->get_json_params();
            $bodyKey = $params['_idempotency_key'] ?? null;
            $k = is_string($bodyKey) ? trim($bodyKey) : '';
        }
        return $k === '' ? null : $k;
    }

    private function mutate(\WP_REST_Request $r,string $operation,callable $callback,int $success=200): mixed
    {
        if(strlen((string)$r->get_body()) > Validator::MAX_BODY_BYTES) return new \WP_Error('payload_too_large',__('JSON body exceeds 64 KiB.','digiforge'),['status'=>413]);
        $header=trim((string)$r->get_header('Idempotency-Key'));
        if($header===''){
            $params=(array)$r->get_json_params();
            $bodyKeyPresent=array_key_exists('_idempotency_key',$params);
            $bodyKey=$bodyKeyPresent?$params['_idempotency_key']:null;
            if($bodyKeyPresent&&!is_string($bodyKey)) return new \WP_Error('invalid_idempotency_key',__('The _idempotency_key JSON field must be a string.','digiforge'),['status'=>400]);
            $header=is_string($bodyKey)?trim($bodyKey):'';
        }
        if($header==='') return new \WP_Error('missing_idempotency_key',__('Idempotency-Key header or _idempotency_key JSON field is required.','digiforge'),['status'=>400]);
        if(strlen($header)>191) return new \WP_Error('invalid_idempotency_key',__('Idempotency key is too long.','digiforge'),['status'=>400]);
        $storage=hash('sha256',$operation.'|'.$header); $guard=new Idempotency();
        if(!$guard->reserve($storage,$operation)) return new \WP_Error('idempotency_conflict',__('This listing mutation has already been submitted.','digiforge'),['status'=>409]);
        try{$result=$callback();}catch(\Throwable){$guard->release($storage);return new \WP_Error('listing_mutation_failed',__('Listing mutation failed.','digiforge'),['status'=>500]);}
        if(is_wp_error($result)){$guard->release($storage);return $result;}
        $encoded=wp_json_encode($result); if(!$guard->complete($storage,is_string($encoded)?$encoded:'')) return new \WP_Error('idempotency_finalize_failed',__('Mutation completed but idempotency state could not be finalized.','digiforge'),['status'=>500]);
        return new \WP_REST_Response($result,$success);
    }
}
