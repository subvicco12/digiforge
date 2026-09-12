<?php
declare(strict_types=1);
namespace DigiForge\REST;

use DigiForge\Core\Capabilities;
use DigiForge\Queue\Idempotency;
use DigiForge\Research\Repository;

final class ResearchController {
    private const NS='digiforge/v1';
    public function register():void{
        add_action('rest_api_init',function():void{
            register_rest_route(self::NS,'/research/(?P<entity>sources|observations|evidence|candidates|reviews)',['methods'=>'GET','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'list']]);
            register_rest_route(self::NS,'/research/sources',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createSource']]);
            register_rest_route(self::NS,'/research/observations',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'ingest']]);
            register_rest_route(self::NS,'/research/observations/(?P<id>\d+)/evidence',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'addEvidence']]);
            register_rest_route(self::NS,'/research/candidates',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createCandidate']]);
            register_rest_route(self::NS,'/research/candidates/(?P<id>\d+)/evidence/(?P<evidence_id>\d+)',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'linkEvidence']]);
            register_rest_route(self::NS,'/research/candidates/(?P<id>\d+)/review',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'review']]);
            register_rest_route(self::NS,'/research/candidates/(?P<id>\d+)/promote',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'promote']]);
        });
    }
    public function canManage():bool{return Capabilities::can('manage_digiforge_research');}
    public function list(\WP_REST_Request $r):\WP_REST_Response{return new \WP_REST_Response((new Repository())->list((string)$r['entity'],(int)($r['page']?:1),(int)($r['per_page']?:20)));}
    public function createSource(\WP_REST_Request $r):mixed{return $this->mutate($r,'research_source_create',fn()=>(new Repository())->createSource((array)$r->get_json_params(),$this->key($r)),201);}
    public function ingest(\WP_REST_Request $r):mixed{return $this->mutate($r,'research_observation_ingest',fn()=>(new Repository())->ingest((array)$r->get_json_params(),$this->key($r)),201);}
    public function addEvidence(\WP_REST_Request $r):mixed{return $this->mutate($r,'research_evidence_create_'.(int)$r['id'],fn()=>(new Repository())->addEvidence((int)$r['id'],(array)$r->get_json_params(),$this->key($r)),201);}
    public function createCandidate(\WP_REST_Request $r):mixed{return $this->mutate($r,'research_candidate_create',fn()=>(new Repository())->createCandidate((array)$r->get_json_params(),$this->key($r)),201);}
    public function linkEvidence(\WP_REST_Request $r):mixed{return $this->mutate($r,'research_candidate_evidence_'.(int)$r['id'].'_'.(int)$r['evidence_id'],fn()=>(new Repository())->linkEvidence((int)$r['id'],(int)$r['evidence_id']));}
    public function review(\WP_REST_Request $r):mixed{$p=(array)$r->get_json_params();return $this->mutate($r,'research_candidate_review_'.(int)$r['id'],fn()=>(new Repository())->review((int)$r['id'],(string)($p['decision']??''),(string)($p['notes']??'')));}
    public function promote(\WP_REST_Request $r):mixed{return $this->mutate($r,'research_candidate_promote_'.(int)$r['id'],fn()=>(new Repository())->promote((int)$r['id'],$this->key($r)));}
    private function key(\WP_REST_Request $r):?string{$k=trim((string)$r->get_header('Idempotency-Key'));return $k===''?null:$k;}
    private function mutate(\WP_REST_Request $r,string $operation,callable $callback,int $successStatus=200):mixed{
        $header=trim((string)$r->get_header('Idempotency-Key'));
        if($header==='')return new \WP_Error('missing_idempotency_key',__('Idempotency-Key header is required.','digiforge'),['status'=>400]);
        $storageKey=hash('sha256',$operation.'|'.$header);
        $idempotency=new Idempotency();
        if(!$idempotency->reserve($storageKey,$operation))return new \WP_Error('idempotency_conflict',__('This mutation has already been submitted.','digiforge'),['status'=>409]);
        try{$result=$callback();}catch(\Throwable $e){$idempotency->release($storageKey);return new \WP_Error('research_mutation_failed',__('Research mutation failed.','digiforge'),['status'=>500]);}
        if(is_wp_error($result)){$idempotency->release($storageKey);return $result;}
        $encoded=wp_json_encode($result);
        if(!$idempotency->complete($storageKey,is_string($encoded)?$encoded:''))return new \WP_Error('idempotency_finalize_failed',__('Mutation completed but idempotency state could not be finalized.','digiforge'),['status'=>500]);
        return new \WP_REST_Response($result,$successStatus);
    }
}
