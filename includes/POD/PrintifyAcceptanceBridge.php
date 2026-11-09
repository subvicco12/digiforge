<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Core\Settings;
use DigiForge\Listings\Validator;
use DigiForge\PortalAccess\{Owner,Store};
use DigiForge\Security\{Logger,ReviewedLocalEvidence,ShopAccess};
use WP_Error;
/** Acceptance overlay, never a replacement for provider activation, grants or execution interlocks. */
final class PrintifyAcceptanceBridge {
 private static function error(string $code):WP_Error{return new WP_Error('printify_acceptance_'.$code,'Acceptance blocked: '.$code.'.',['status'=>409,'retry_permitted'=>false]);}
 public static function permission():bool{return get_current_user_id()>0&&current_user_can('manage_digiforge_connections')&&Owner::require('personalized_pod')===true&&ShopAccess::authorize('personalized_pod','manage_digiforge_connections')===true;}
 private static function session():string{return hash_hmac('sha256',wp_get_session_token(),wp_salt('auth'));}
 private function save(string $kind,array $r):array|WP_Error {
  $r['seal']=hash_hmac('sha256',Validator::canonicalJson($r),wp_salt('auth'));$raw=Validator::canonicalJson($r);if(strlen($raw)>131072)return self::error('size');$hash=hash('sha256',$raw);
  if(!add_option('df_acceptance_'.$kind.'_'.$hash,$raw,'',false)||!Logger::write('printify_acceptance_'.$kind,['record_sha256'=>$hash],'printify_acceptance',$hash))return self::error('audit');return ['hash'=>$hash,'record'=>$r];
 }
 private function load(string $kind,string $hash):array|WP_Error {
  if(!self::permission()||!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('authority');global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s','df_acceptance_'.$kind.'_'.$hash));
  if($wpdb->last_error!==''||!is_string($raw)||strlen($raw)>131072||!hash_equals($hash,hash('sha256',$raw)))return self::error('integrity');
  try{$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$seal=$r['seal']??null;unset($r['seal']);if(!is_string($seal)||!hash_equals($seal,hash_hmac('sha256',Validator::canonicalJson($r),wp_salt('auth'))))return self::error('integrity');}catch(\Throwable){return self::error('integrity');}
  $wpdb->last_error='';$anchors=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.\DigiForge\Database\Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s LIMIT 2','printify_acceptance_'.$kind,'printify_acceptance',$hash));
  if($wpdb->last_error!==''||!is_array($anchors)||count($anchors)!==1||(json_decode($anchors[0],true)['record_sha256']??null)!==$hash)return self::error('audit');return $r;
 }
 /** Proposal only. All targets must already be exact, non-secret, governed plans/grants. */
 public function prepare(int $integration,array $targets):array|WP_Error {
  if(!self::permission()||!array_is_list($targets)||!$targets||count($targets)>10||wp_get_session_token()==='')return self::error('authority');
  $generation=PrintifyAcceptanceSafetyGeneration::current();if(is_wp_error($generation))return self::error('safety_generation');
  $scope=PrintifyConnectionBoundary::context('personalized_pod',$integration,PrintifyApiReadWorkflow::CAPABILITY);if(is_wp_error($scope))return self::error('reviewed_binding');
  $slots=[];$seen=[];foreach($targets as $t){if(!is_array($t)||array_diff(array_keys($t),['pipeline','plan_hash','index'])||!is_string($t['pipeline']??null)||!is_string($t['plan_hash']??null))return self::error('target');if(isset($t['index'])&&(!is_int($t['index'])||$t['index']<0||$t['index']>9))return self::error('target');$plan=$this->target($t);if(is_wp_error($plan))return $plan;
   $s=$plan['scope'];foreach(['integration_id','external_account_reference','external_shop_reference','shop_key'] as $k)if(($s[$k]??null)!==($scope[$k]??null))return self::error('shop_scope');
   $identity=hash('sha256',Validator::canonicalJson($t));if(isset($seen[$identity]))return self::error('duplicate_target');$seen[$identity]=true;$slots[]=['target'=>$t,'descriptor'=>$plan['descriptor'],'scope_hash'=>hash('sha256',Validator::canonicalJson($s))];
  }
  $profile=Store::read(get_current_user_id());if(is_wp_error($profile)||!is_array($profile))return self::error('owner');
  $r=$this->save('session',['schema'=>'printify-acceptance-session-v1','safety_generation'=>$generation,'actor'=>get_current_user_id(),'browser'=>self::session(),'profile_hash'=>hash('sha256',Validator::canonicalJson($profile)),'site_hash'=>hash('sha256',home_url('/')),'integration_id'=>$integration,'shop_key'=>'personalized_pod','provider_shop_id'=>$scope['external_shop_reference'],'account_sha256'=>hash('sha256',$scope['external_account_reference']),'scope'=>$scope,'slots'=>$slots,'issued_at'=>time(),'expires_at'=>time()+300,'nonce'=>bin2hex(random_bytes(16))]);
  return is_wp_error($r)?$r:['hash'=>$r['hash'],'status'=>'PROPOSAL_ONLY','expires_at'=>$r['record']['expires_at'],'targets'=>array_column($slots,'descriptor')];
 }
 public function approvalPayload(string $hash):array|WP_Error {
  $s=$this->load('session',$hash);if(is_wp_error($s))return $s;
  return ['purpose'=>'printify-get-acceptance-v1','safety_generation'=>$s['safety_generation']??null,'session_hash'=>$hash,'actor'=>$s['actor'],'integration_id'=>$s['integration_id'],'shop_key'=>$s['shop_key'],'provider_shop_id'=>$s['provider_shop_id'],'account_sha256'=>$s['account_sha256'],'site_hash'=>$s['site_hash'],'expires_at'=>$s['expires_at'],'max_requests'=>10,'requests_per_minute'=>3,'max_page'=>3,'targets'=>array_column($s['slots'],'descriptor'),'overrides_execution_locks'=>false];
 }
 private function current(string $hash):array|WP_Error {
  $s=$this->load('session',$hash);if(is_wp_error($s))return $s;
  if(PrintifyAcceptanceSafetyGeneration::check($s['safety_generation']??null)!==true)return self::error('safety_generation');
  $profile=Store::read(get_current_user_id());if(!is_array($profile))return self::error('owner');
  if($s['actor']!==get_current_user_id()||$s['browser']!==self::session()||$s['profile_hash']!==hash('sha256',Validator::canonicalJson($profile))||$s['site_hash']!==hash('sha256',home_url('/'))||$s['issued_at']>time()||$s['expires_at']<=time()||$s['expires_at']-$s['issued_at']>300)return self::error('expired_or_session');
  global $wpdb;$wpdb->last_error='';$revoked=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','df_acceptance_revoked_'.$hash));if($wpdb->last_error!==''||$revoked!==null)return self::error('revoked');
  $scope=PrintifyConnectionBoundary::context('personalized_pod',$s['integration_id'],PrintifyApiReadWorkflow::CAPABILITY);if(is_wp_error($scope)||Validator::canonicalJson($scope)!==Validator::canonicalJson($s['scope']))return self::error('binding_changed');
  $policy=ReviewedLocalEvidence::approved('policy','personalized_pod','printify-acceptance:'.$hash);$expected=$this->approvalPayload($hash);if(is_wp_error($policy)||is_wp_error($expected)||Validator::canonicalJson($policy['payload']??[])!==Validator::canonicalJson($expected))return self::error('review_required');return $s;
 }
 public function authorize(string $hash):array|WP_Error{$s=$this->current($hash);return is_wp_error($s)?$s:['hash'=>$hash,'status'=>'ACCEPTANCE_AUTHORITY_ONLY','execution_locks_overridden'=>false];}
 public function revoke(string $hash):true|WP_Error{$s=$this->load('session',$hash);if(is_wp_error($s)||$s['actor']!==get_current_user_id())return self::error('authority');return add_option('df_acceptance_revoked_'.$hash,time(),'',false)&&Logger::write('printify_acceptance_revoked',['session_sha256'=>$hash],'printify_acceptance',$hash)?true:self::error('revocation');}
 private function target(array $t):array|WP_Error {
  $kind=$t['pipeline'];$hash=$t['plan_hash'];if(!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('target');
  if($kind==='catalog'){$p=(new PrintifyCatalogReadRepository())->acceptancePlan($hash,$t['index']??-1);if(is_wp_error($p))return self::error('catalog_grant');$request=$p['request'];$scope=$p['scope'];}
  else{$w=match($kind){'read'=>new PrintifyApiReadWorkflow(),'lifecycle'=>new PrintifyLifecycleWorkflow(),'product'=>new PrintifyProductPipeline(),default=>null};if($w===null||isset($t['index']))return self::error('target');$p=$w->plan($hash);if(is_wp_error($p))return self::error('upstream_plan');$request=$p['request'];$scope=$p['connection_scope'];}
  try{$descriptor=PrintifyAcceptanceContract::inspect($request,$scope['external_shop_reference']);}catch(\Throwable){return self::error('get_allowlist');}
  return ['request'=>$request,'scope'=>$scope,'descriptor'=>$descriptor];
 }
 /** The only caller supplies the existing controlled transport's server-side sender. */
 public function execute(string $hash,int $slot,string $authorization,callable $sender):array|WP_Error {
  $s=$this->current($hash);if(is_wp_error($s))return $s;if(!isset($s['slots'][$slot])||$slot<0)return self::error('slot');
  // Existing policy prohibits ordinary GETs here. This overlay cannot grant an exception.
  if(Settings::safety_locked()||Settings::get('stop_all',true)!==false)return self::error('blocked_authority_locks');
  $entry=$s['slots'][$slot];$p=$this->target($entry['target']);if(is_wp_error($p)||hash('sha256',Validator::canonicalJson($p['scope']))!==$entry['scope_hash']||Validator::canonicalJson($p['descriptor'])!==Validator::canonicalJson($entry['descriptor']))return self::error('target_changed');
  $reserved=$this->reserve($hash,$slot,$s);if(is_wp_error($reserved))return $reserved;
  $http=null;$retry=null;$attempted=false;$blocked=null;$observations=['values_disclosed'=>false];$pagination=[];$rate=[];
  $guard=function(string $url,array $args)use($hash,$p,$sender,&$http,&$retry,&$attempted,&$blocked,&$observations,&$pagination,&$rate){
   if($attempted)return self::error('no_retry');
   $fresh=$this->current($hash);if(is_wp_error($fresh)||Settings::safety_locked()||Settings::get('stop_all',true)!==false){$blocked='AUTHORITY_CHANGED';return self::error('authority_changed');}
   try{PrintifyAcceptanceContract::transport($p['request']['endpoint'],$url,$args);}catch(\Throwable){$blocked='TRANSPORT_CONFINEMENT';return self::error('transport');}
   $attempted=true;try{$r=$sender($url,$args);}catch(\Throwable){$blocked='TRANSPORT_FAILURE';return self::error('transport_failure');}
   if(!is_wp_error($r)){$http=(int)wp_remote_retrieve_response_code($r);$raw=(string)wp_remote_retrieve_header($r,'retry-after');$retry=ctype_digit($raw)?min(3600,(int)$raw):($raw!==''?'PRESENT_UNPARSED':null);
    foreach(['x-ratelimit-limit','x-ratelimit-remaining','x-ratelimit-reset'] as $header){$v=(string)wp_remote_retrieve_header($r,$header);if(ctype_digit($v)&&strlen($v)<=10)$rate[$header]=(int)$v;}
    $body=(string)wp_remote_retrieve_body($r);if(strlen($body)<=1048576){try{$decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR);$observations=PrintifyAcceptanceContract::observations($decoded);if(is_array($decoded))foreach(['current_page','last_page','per_page'] as $field)if(is_int($decoded[$field]??null)&&$decoded[$field]>=0&&$decoded[$field]<=2147483647)$pagination[$field]=$decoded[$field];}catch(\Throwable){$blocked='INVALID_JSON';}}else{$blocked='OVERSIZED_RESPONSE';}
   }
   return $r;
  };
  $t=$entry['target'];try{$result=match($t['pipeline']){'read'=>(new PrintifyApiReadWorkflow())->execute($t['plan_hash'],$authorization,$guard),'lifecycle'=>(new PrintifyLifecycleWorkflow())->execute($t['plan_hash'],$authorization,$guard),'product'=>(new PrintifyProductPipeline())->execute($t['plan_hash'],$authorization,$guard),'catalog'=>(new PrintifyCatalogReadRepository(new PrintifyCatalogClient($guard)))->read($t['plan_hash'],$t['index'],'acceptance_'.$hash.'_'.$slot)};}catch(\Throwable){$result=self::error('upstream_failure');}
  $fresh=$this->current($hash);$ok=!is_wp_error($result)&&!is_wp_error($fresh)&&($result['record']['status']??null)==='SUCCEEDED'&&$http===200;
  if($t['pipeline']==='catalog')$ok=!is_wp_error($result)&&!is_wp_error($fresh)&&$http===200;
  if(is_wp_error($fresh)&&$attempted)$blocked='AUTHORITY_CHANGED_OUTCOME_UNKNOWN';
  $record=['schema'=>'printify-acceptance-receipt-v1','session_hash'=>$hash,'slot'=>$slot,'actor'=>$s['actor'],'operation'=>$entry['descriptor']['operation'],'endpoint_template'=>$entry['descriptor']['endpoint_template'],'endpoint_sha256'=>$entry['descriptor']['endpoint_sha256'],'upstream_plan_sha256'=>$entry['target']['plan_hash'],'api_version'=>$entry['descriptor']['api_version'],'provider_shop_id'=>$s['provider_shop_id'],'account_sha256'=>$s['account_sha256'],'integration_id'=>$s['integration_id'],'http_status'=>$http,'observed_at'=>gmdate('c'),'correlation_id'=>hash('sha256',$hash.':'.$slot),'page'=>$entry['descriptor']['page'],'retry_after_seconds'=>$retry,'network_request_attempted'=>$attempted,'compatibility'=>$ok?'SCHEMA_ACCEPTED':'UNVERIFIED','reason'=>$ok?'PROJECTED_SUCCESS':($blocked??match($http){401=>'CREDENTIAL_REJECTED',403=>'SCOPE_REJECTED',429=>'RATE_LIMITED',500,502,503,504=>'PROVIDER_UNAVAILABLE',default=>'UPSTREAM_OR_SCHEMA_REJECTED'}),'observations'=>!is_wp_error($fresh)?$observations:['values_disclosed'=>false],'pagination'=>$pagination,'rate_limit_metadata'=>$rate,'source_receipt_sha256'=>!is_wp_error($result)?($result['hash']??$result['sha256']??null):null];
  $record['response_schema_sha256']=hash('sha256',Validator::canonicalJson($record['observations']));$saved=$this->save('receipt',$record);return is_wp_error($saved)?$saved:['hash'=>$saved['hash'],'receipt'=>json_decode(Validator::canonicalJson($record),true)];
 }
 private function reserve(string $hash,int $slot,array $s):true|WP_Error {
  $durable=PrintifyProductPipeline::durableDispatchSession();if(is_wp_error($durable))return self::error('durability');global $wpdb;$key='dfa_'.substr($s['account_sha256'],0,40);$wpdb->last_error='';if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!=='1'||$wpdb->last_error!=='')return self::error('concurrent');
  try{$fresh=$this->current($hash);if(is_wp_error($fresh))return $fresh;$minute='df_acceptance_window_'.$s['account_sha256'];$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$minute));$times=$raw===null?[]:json_decode($raw,true);if($wpdb->last_error!==''||!is_array($times)||!array_is_list($times)||count($times)>3)return self::error('quota_evidence');$now=time();foreach($times as $t)if(!is_int($t)||$t>$now)return self::error('quota_evidence');$times=array_values(array_filter($times,static fn($t)=>$t>$now-60));if(count($times)>=3)return self::error('quota');$times[]=$now;$window=wp_json_encode($times);
   if(!add_option('df_acceptance_claim_'.$hash.'_'.$slot,time(),'',false))return self::error('consumed');
   if(($raw===null?!add_option($minute,$window,'',false):!update_option($minute,$window,false))||!Logger::write('printify_acceptance_reserved',['session_sha256'=>$hash,'slot'=>$slot,'maximum_physical_requests'=>1],'printify_acceptance',$hash))return self::error('quota_audit');return true;
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
 }
 public function receipt(string $hash):array|WP_Error{$r=$this->load('receipt',$hash);if(is_wp_error($r))return $r;$s=$this->load('session',$r['session_hash']);return is_wp_error($s)||$s['actor']!==get_current_user_id()||$s['site_hash']!==hash('sha256',home_url('/'))?self::error('receipt_owner'):['hash'=>$hash,'receipt'=>$r];}
}
