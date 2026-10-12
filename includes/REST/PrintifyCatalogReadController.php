<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\POD\PrintifyCatalogReadRepository as Reads;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
/** Main-site, account-bound catalog READ routes; grant/revoke only mutate local evidence. */
final class PrintifyCatalogReadController {
 public function register():void {add_action('rest_api_init',function(){
  register_rest_route('digiforge/v1','/printify/catalog-read/grants',['methods'=>'POST','permission_callback'=>[$this,'canApprove'],'callback'=>[$this,'approve']]);
  register_rest_route('digiforge/v1','/printify/catalog-read/(?P<sha>[a-f0-9]{64})/safety-exception',['methods'=>'POST','permission_callback'=>[$this,'canApprove'],'callback'=>[$this,'approveSafetyException']]);
  register_rest_route('digiforge/v1','/printify/catalog-read/(?P<sha>[a-f0-9]{64})/safety-exception/revoke',['methods'=>'POST','permission_callback'=>[$this,'canApprove'],'callback'=>[$this,'revokeSafetyException']]);
  register_rest_route('digiforge/v1','/printify/catalog-read/(?P<sha>[a-f0-9]{64})/revoke',['methods'=>'POST','permission_callback'=>[$this,'canApprove'],'callback'=>[$this,'revoke']]);
  register_rest_route('digiforge/v1','/printify/catalog-read/(?P<sha>[a-f0-9]{64})',['methods'=>'GET','permission_callback'=>[$this,'canRead'],'callback'=>[$this,'read']]);
  register_rest_route('digiforge/v1','/printify/catalog-read/(?P<sha>[a-f0-9]{64})/evidence/(?P<request_sha>[a-f0-9]{64})',['methods'=>'GET','permission_callback'=>[$this,'canRead'],'callback'=>[$this,'evidence']]);
 });}
 public function canApprove(WP_REST_Request $r):true|WP_Error {return $this->permission($r,Reads::owner());}
 public function canRead(WP_REST_Request $r):true|WP_Error {return $this->permission($r,Reads::reader());}
 private function permission(WP_REST_Request $r,bool $allowed):true|WP_Error {return $allowed&&wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest')?true:new WP_Error('catalog_read_forbidden','Authenticated scoped account and WordPress REST nonce required.',['status'=>403]);}
 public function approve(WP_REST_Request $r):WP_REST_Response|WP_Error {if($e=$this->guard($r,true))return $e;$body=$r->get_json_params();if(!is_array($body))return new WP_Error('catalog_read_body','JSON object required.',['status'=>400]);return $this->response((new Reads())->approve($body,(string)$r->get_header('Idempotency-Key')));}
 public function approveSafetyException(WP_REST_Request $r):WP_REST_Response|WP_Error {if($e=$this->guard($r,true))return $e;$body=$r->get_json_params();if(!is_array($body)||$r->get_query_params()!==[])return new WP_Error('catalog_read_body','Exact exception JSON with no query fields required.',['status'=>400]);return $this->response((new Reads())->approveSafetyException((string)$r['sha'],$body,(string)$r->get_header('Idempotency-Key')));}
 public function revokeSafetyException(WP_REST_Request $r):WP_REST_Response|WP_Error {if($e=$this->guard($r,true))return $e;if((string)$r->get_body()!==''||$r->get_query_params()!==[])return new WP_Error('catalog_read_body','No exception revoke payload accepted.',['status'=>400]);$v=(new Reads())->revokeSafetyException((string)$r['sha']);return $this->response($v instanceof WP_Error?$v:['safety_exception_revoked'=>true]);}
 public function revoke(WP_REST_Request $r):WP_REST_Response|WP_Error {if($e=$this->guard($r,true))return $e;if((string)$r->get_body()!==''||$r->get_query_params()!==[])return new WP_Error('catalog_read_body','No revoke payload is accepted.',['status'=>400]);$result=(new Reads())->revoke((string)$r['sha']);return $this->response($result instanceof WP_Error?$result:['revoked'=>true]);}
 public function read(WP_REST_Request $r):WP_REST_Response|WP_Error {if($e=$this->guard($r,false))return $e;$q=$r->get_query_params();if(array_diff(array_keys($q),['request_index'])||!isset($q['request_index'])||!preg_match('/^[0-9]$/D',(string)$q['request_index'])||(string)$r->get_body()!=='')return new WP_Error('catalog_read_query','Only an explicit request_index is accepted.',['status'=>400]);return $this->response((new Reads())->read((string)$r['sha'],(int)$q['request_index'],(string)$r->get_header('Idempotency-Key')));}
 public function evidence(WP_REST_Request $r):WP_REST_Response|WP_Error {if($e=$this->guard($r,false))return $e;if($r->get_query_params()!==[]||(string)$r->get_body()!=='')return new WP_Error('catalog_read_query','No evidence query or payload is accepted.',['status'=>400]);return $this->response((new Reads())->evidence((string)$r['sha'],(string)$r['request_sha']));}
 private function guard(WP_REST_Request $r,bool $owner):?WP_Error {$permission=$owner?$this->canApprove($r):$this->canRead($r);if($permission instanceof WP_Error)return $permission;if(strlen((string)$r->get_body())>8192)return new WP_Error('catalog_read_body_size','Grant body limit is 8 KiB.',['status'=>413]);return null;}
 private function response(array|WP_Error $v):WP_REST_Response|WP_Error {if($v instanceof WP_Error)return $v;$r=new WP_REST_Response($v);$r->header('Cache-Control','private, no-store');return $r;}
}
