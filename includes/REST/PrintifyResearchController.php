<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\POD\PrintifyResearchBatch;
use DigiForge\POD\PrintifyResearchGateway;
use DigiForge\POD\PrintifyResearchMapper;
use DigiForge\POD\ProductIntelligence;
use DigiForge\POD\ProductIntelligenceRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Fixed Shop 2 research API; no raw URLs, credentials, grants or provider mutations. */
final class PrintifyResearchController
{
    public function register(): void
    {
        add_action('rest_api_init',function():void{
            $base='/shops/personalized_pod/printify-research';
            foreach([
                [$base.'/readiness','GET','readiness'],
                [$base.'/snapshots/(?P<sha>[a-f0-9]{64})','GET','snapshot'],
                [$base.'/receipts','POST','ingest'],
                [$base.'/batches','POST','prepare'],
                [$base.'/batches/(?P<sha>[a-f0-9]{64})','GET','status'],
                [$base.'/batches/(?P<sha>[a-f0-9]{64})/step','POST','step'],
            ] as [$route,$method,$callback])register_rest_route('digiforge/v1',$route,['methods'=>$method,'permission_callback'=>[$this,'permission'],'callback'=>[$this,$callback]]);
        });
    }
    public function permission(WP_REST_Request $r): true|WP_Error
    {
        if(!PrintifyResearchBatch::allowed()||!wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest'))return PrintifyResearchGateway::error('forbidden',403);
        if(class_exists(\DigiForge\Security\ShopAccess::class))foreach(['manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'] as $cap){$access=\DigiForge\Security\ShopAccess::authorize('personalized_pod',$cap);if(is_wp_error($access))return $access;}
        return true;
    }
    public function readiness(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        if(($error=$this->guard($r))!==null)return $error;if($r->get_query_params()!==[]||(string)$r->get_body()!=='')return PrintifyResearchGateway::error('query',400);return $this->response(PrintifyResearchGateway::readiness());
    }
    public function snapshot(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        if(($error=$this->guard($r))!==null)return $error;
        $q=$r->get_query_params();if(array_diff(array_keys($q),['section','query','page'])||(string)$r->get_body()!=='')return PrintifyResearchGateway::error('query',400);
        $section=$q['section']??'providers';$query=$q['query']??'';$page=$q['page']??'1';
        if(!is_string($section)||!in_array($section,ProductIntelligence::SECTIONS,true)||!is_string($query)||strlen($query)>120||!preg_match('/^[1-9][0-9]{0,5}$/D',(string)$page))return PrintifyResearchGateway::error('query',400);
        $packet=(new ProductIntelligenceRepository())->read((string)$r['sha']);if(is_wp_error($packet))return $packet;
        return $this->response(['snapshot_id'=>(string)$r['sha'],'shop_key'=>'personalized_pod','section'=>$section,'sources'=>$packet['sources'],'evidence'=>ProductIntelligence::page($packet,$section,$query,(int)$page),'approval_status'=>'UNREVIEWED','external_execution_authorized'=>false]);
    }
    public function prepare(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $body=$this->body($r,['snapshot_id','blueprint_ids']);if(is_wp_error($body))return $body;
        if(!is_string($body['snapshot_id'])||!is_array($body['blueprint_ids']))return PrintifyResearchGateway::error('body',400);
        return $this->response((new PrintifyResearchBatch())->create($body['snapshot_id'],$body['blueprint_ids']));
    }
    public function status(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        if(($error=$this->guard($r))!==null)return $error;if($r->get_query_params()!==[]||(string)$r->get_body()!=='')return PrintifyResearchGateway::error('query',400);return $this->response((new PrintifyResearchBatch())->status((string)$r['sha']));
    }
    public function step(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $body=$this->body($r,['grant_sha256','confirm_catalog_get']);if(is_wp_error($body))return $body;
        if(!is_string($body['grant_sha256'])||$body['confirm_catalog_get']!==true)return PrintifyResearchGateway::error('explicit_collection_required',400);
        return $this->response((new PrintifyResearchBatch())->step((string)$r['sha'],$body['grant_sha256']));
    }
    public function ingest(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $body=$this->body($r,['snapshot_id','grant_sha256','request_sha256']);if(is_wp_error($body))return $body;
        foreach($body as $value)if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/D',$value))return PrintifyResearchGateway::error('body',400);
        $base=(new ProductIntelligenceRepository())->read($body['snapshot_id']);if(is_wp_error($base))return $base;
        $receipt=(new PrintifyResearchGateway())->evidence($body['grant_sha256'],$body['request_sha256']);if(is_wp_error($receipt))return $receipt;
        try{$packet=PrintifyResearchMapper::append($base,$receipt);}catch(\Throwable){return PrintifyResearchGateway::error('receipt',503);}
        return $this->response((new ProductIntelligenceRepository())->import($packet));
    }
    private function guard(WP_REST_Request $r): ?WP_Error {$permission=$this->permission($r);if(is_wp_error($permission))return $permission;return strlen((string)$r->get_body())>8192?PrintifyResearchGateway::error('body_size',413):null;}
    private function body(WP_REST_Request $r,array $keys): array|WP_Error
    {
        if(($error=$this->guard($r))!==null)return $error;$body=$r->get_json_params();
        return is_array($body)&&count($body)===count($keys)&&!array_diff(array_keys($body),$keys)&&$r->get_query_params()===[]?$body:PrintifyResearchGateway::error('body',400);
    }
    private function response(array|WP_Error $value): WP_REST_Response|WP_Error {if(is_wp_error($value))return $value;$response=new WP_REST_Response($value);$response->header('Cache-Control','private, no-store');return $response;}
}
