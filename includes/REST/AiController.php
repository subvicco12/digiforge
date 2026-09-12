<?php
declare(strict_types=1);
namespace DigiForge\REST;

use DigiForge\AI\Repository;
use DigiForge\Queue\Idempotency;

final class AiController {
    private const NS='digiforge/v1';
    public function register():void{
        add_action('rest_api_init',function():void{
            register_rest_route(self::NS,'/ai/(?P<entity>tasks|models|prompts|prompt-versions|runs|outputs|usage|reviews)',['methods'=>'GET','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'list']]);
            register_rest_route(self::NS,'/ai/tasks',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createTask']]);
            register_rest_route(self::NS,'/ai/models',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createModel']]);
            register_rest_route(self::NS,'/ai/prompts',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createPrompt']]);
            register_rest_route(self::NS,'/ai/prompts/(?P<id>\d+)/versions',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createPromptVersion']]);
            register_rest_route(self::NS,'/ai/runs',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'createRun']]);
            register_rest_route(self::NS,'/ai/runs/(?P<id>\d+)/state',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'transition']]);
            register_rest_route(self::NS,'/ai/runs/(?P<id>\d+)/outputs',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'storeOutput']]);
            register_rest_route(self::NS,'/ai/runs/(?P<id>\d+)/usage',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'recordUsage']]);
            register_rest_route(self::NS,'/ai/runs/(?P<id>\d+)/reviews',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'review']]);
        });
    }
    public function canManage():bool{return current_user_can('manage_digiforge_ai');}
    public function list(\WP_REST_Request $r):\WP_REST_Response{$result=(new Repository())->list((string)$r['entity'],max(1,(int)($r['page']?:1)),min(Repository::MAX_PAGE_SIZE,max(1,(int)($r['per_page']?:Repository::DEFAULT_PAGE_SIZE))));$response=new \WP_REST_Response($result['items']);$response->header('X-WP-Total',(string)$result['pagination']['total_items']);$response->header('X-WP-TotalPages',(string)$result['pagination']['total_pages']);return $response;}
    public function createTask(\WP_REST_Request $r):mixed{return $this->mutate($r,'ai_task_create',fn()=>(new Repository())->createTask((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function createModel(\WP_REST_Request $r):mixed{return $this->mutate($r,'ai_model_create',fn()=>(new Repository())->createModel((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function createPrompt(\WP_REST_Request $r):mixed{return $this->mutate($r,'ai_prompt_create',fn()=>(new Repository())->createPrompt((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function createPromptVersion(\WP_REST_Request $r):mixed{return $this->mutate($r,'ai_prompt_version_'.(int)$r['id'],fn()=>(new Repository())->createPromptVersion((int)$r['id'],(array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function createRun(\WP_REST_Request $r):mixed{return $this->mutate($r,'ai_run_create',fn()=>(new Repository())->createRun((array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function transition(\WP_REST_Request $r):mixed{$p=(array)$r->get_json_params();return $this->mutate($r,'ai_run_state_'.(int)$r['id'].'_'.sanitize_key((string)($p['state']??'')),fn()=>(new Repository())->transitionRun((int)$r['id'],(string)($p['state']??'')));}
    public function storeOutput(\WP_REST_Request $r):mixed{return $this->mutate($r,'ai_output_'.(int)$r['id'],fn()=>(new Repository())->storeOutput((int)$r['id'],(array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function recordUsage(\WP_REST_Request $r):mixed{return $this->mutate($r,'ai_usage_'.(int)$r['id'],fn()=>(new Repository())->recordUsage((int)$r['id'],(array)$r->get_json_params(),$this->rawKey($r)),201);}
    public function review(\WP_REST_Request $r):mixed{return $this->mutate($r,'ai_review_'.(int)$r['id'],fn()=>(new Repository())->review((int)$r['id'],(array)$r->get_json_params(),$this->rawKey($r)),201);}
    private function rawKey(\WP_REST_Request $r):?string{$k=trim((string)$r->get_header('Idempotency-Key'));return $k===''?null:$k;}
    private function mutate(\WP_REST_Request $r,string $operation,callable $callback,int $success=200):mixed{
        $header=trim((string)$r->get_header('Idempotency-Key'));if($header==='')return new \WP_Error('missing_idempotency_key',__('Idempotency-Key header is required.','digiforge'),['status'=>400]);
        $storage=hash('sha256',$operation.'|'.$header);$guard=new Idempotency();if(!$guard->reserve($storage,$operation))return new \WP_Error('idempotency_conflict',__('This AI mutation has already been submitted.','digiforge'),['status'=>409]);
        try{$result=$callback();}catch(\Throwable){$guard->release($storage);return new \WP_Error('ai_mutation_failed',__('AI governance mutation failed.','digiforge'),['status'=>500]);}
        if(is_wp_error($result)){$guard->release($storage);return $result;}$encoded=wp_json_encode($result);if(!$guard->complete($storage,is_string($encoded)?$encoded:''))return new \WP_Error('idempotency_finalize_failed',__('Mutation completed but idempotency state could not be finalized.','digiforge'),['status'=>500]);
        return new \WP_REST_Response($result,$success);
    }
}
