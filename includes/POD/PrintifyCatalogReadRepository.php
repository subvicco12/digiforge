<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository;
use DigiForge\Security\Logger;
use WP_Error;

/** Append-only grants, separate catalog-GET approvals and receipts; never changes global safety switches. */
final class PrintifyCatalogReadRepository {
 public function __construct(private ?PrintifyCatalogClient $client=null) {}
 public static function owner():bool {return get_current_user_id()>0&&current_user_can('manage_options')&&current_user_can('manage_digiforge_connections')&&current_user_can('manage_digiforge_pod');}
 public static function reader():bool {return get_current_user_id()>0&&current_user_can('manage_digiforge_connections')&&current_user_can('manage_digiforge_pod');}
 /** Minimal target identity for the existing global REST assignment guard; never grants access. */
 public function targetShopForInput(array $input):string|WP_Error {
  try{PrintifyCatalogReadContract::normalize($input,time());$binding=$this->binding($input,false);return $binding instanceof WP_Error?$binding:$binding['shop_key'];}
  catch(\Throwable){return self::error('invalid_grant',400);}
 }
 public function targetShopForGrant(string $sha):string|WP_Error {
  $record=$this->grant($sha,false);if($record instanceof WP_Error)return $record;
  try{$binding=$this->binding($record['grant'],false);}catch(\Throwable){return self::error('shop_scope',409);}
  if($binding instanceof WP_Error)return $binding;
  if(self::json($binding)!==self::json(array_diff_key($record['binding'],['ownership_binding_sha256'=>true])))return self::error('integration_or_scope_changed',403);
  return $binding['shop_key'];
 }
 public function approve(array $input,string $key):array|WP_Error {
  if(!self::owner())return self::error('owner_required',403);
  if(!self::mainSite())return self::error('main_site_binding_required',409);
  try{$g=PrintifyCatalogReadContract::normalize($input,time());self::requestKey($key);if(!user_can($g['account_id'],'manage_digiforge_connections')||!user_can($g['account_id'],'manage_digiforge_pod'))return self::error('account_scope',403);$binding=$this->binding($g);if($binding instanceof WP_Error)return $binding;
   $r=self::seal(['schema'=>1,'grant'=>$g,'binding'=>$binding,'site_sha256'=>self::site(),'approved_by'=>get_current_user_id(),'approved_at'=>gmdate('c'),'approval_request_sha256'=>hash('sha256',$key)]);
   return $this->locked(hash('sha256','approval|'.get_current_user_id().'|'.$key),function()use($r,$key){return $this->transaction(function()use($r,$key){$name='df_catalog_read_approval_'.hash('sha256',get_current_user_id().'|'.$key);if($this->load($name)!==null)return self::error('approval_replay',409);$a=$this->put($name,['grant_sha256'=>$r['sha256']]);if($a instanceof WP_Error)return $a;$saved=$this->put('df_catalog_read_grant_'.$r['sha256'],$r,'catalog_read_approved');return $saved instanceof WP_Error?$saved:$r;});});
  }catch(\Throwable){return self::error('invalid_grant',400);}
 }
 public function revoke(string $sha):true|WP_Error {
  if(!self::owner())return self::error('owner_required',403);
  return $this->locked($sha,function()use($sha){$g=$this->grant($sha,false);if($g instanceof WP_Error)return $g;return $this->transaction(function()use($sha){$r=self::seal(['grant_sha256'=>$sha,'revoked_by'=>get_current_user_id(),'revoked_at'=>gmdate('c')]);$saved=$this->put('df_catalog_read_revoked_'.$sha,$r,'catalog_read_revoked');return $saved instanceof WP_Error?$saved:true;});});
 }
 /** Local-only, separately authenticated owner approval for exactly one immutable grant. */
 public function approveSafetyException(string $sha,array $input,string $key):array|WP_Error {
  if(!self::owner())return self::error('owner_required',403);
  try{self::requestKey($key);}catch(\Throwable){return self::error('request_key',400);}
  return $this->locked($sha,function()use($sha,$input,$key){
   $g=$this->grant($sha);if($g instanceof WP_Error)return $g;$posture=$this->protectedPosture();if($posture instanceof WP_Error)return $posture;
   if(wp_get_environment_type()!=='production')return self::error('main_site_required',409);
   try{$approval=PrintifyCatalogGetSafetyExceptionContract::normalize($input,$g['grant'],time());}catch(\Throwable){return self::error('invalid_safety_exception',400);}
   $existing=$this->load('df_catalog_get_exception_'.$sha);if($existing!==null)return self::error('exception_immutable_or_unavailable',409);$anchors=$this->exceptionAnchors('catalog_get_exception_approved',$sha);if($anchors instanceof WP_Error)return $anchors;if($anchors!==[])return self::error('exception_evidence_missing',503);
   return $this->transaction(function()use($sha,$approval,$g,$key){
    $name='df_catalog_get_exception_approval_'.hash('sha256',get_current_user_id().'|'.$key);if($this->load($name)!==null)return self::error('exception_approval_replay',409);
    $r=self::seal(['schema'=>1,'capability'=>PrintifyCatalogReadContract::CAPABILITY,'method'=>'GET','grant_sha256'=>$sha,'grant_binding_sha256'=>hash('sha256',self::json($g['binding'])),'site_sha256'=>self::site(),'approval'=>$approval,'approved_by'=>get_current_user_id(),'approved_at'=>gmdate('c'),'approval_request_sha256'=>hash('sha256',$key),'mutation_authorized'=>false]);
    $saved=$this->put($name,['grant_sha256'=>$sha,'exception_sha256'=>$r['sha256']]);if($saved instanceof WP_Error)return $saved;return $this->put('df_catalog_get_exception_'.$sha,$r,'catalog_get_exception_approved');
   });
  });
 }
 public function revokeSafetyException(string $sha):true|WP_Error {
  if(!self::owner())return self::error('owner_required',403);
  return $this->locked($sha,function()use($sha){$g=$this->grant($sha,false);if($g instanceof WP_Error)return $g;$r=$this->load('df_catalog_get_exception_'.$sha);if($r instanceof WP_Error)return $r;if($r===null||!self::sealed($r)||!$this->audited('catalog_get_exception_approved',$r['sha256']))return self::error('exception_evidence',409);
   return $this->transaction(function()use($sha,$r){$revocation=self::seal(['grant_sha256'=>$sha,'exception_sha256'=>$r['sha256'],'revoked_by'=>get_current_user_id(),'revoked_at'=>gmdate('c')]);$saved=$this->put('df_catalog_get_exception_revoked_'.$sha,$revocation,'catalog_get_exception_revoked');return $saved instanceof WP_Error?$saved:true;});
  });
 }
 /** Read-only admission applies only inside this catalog GET pipeline, never Settings or mutation clients. */
 private function readPermission(string $sha,array $grant):array|WP_Error {
  $r=$this->load('df_catalog_get_exception_'.$sha);if($r instanceof WP_Error)return $r;
  $anchors=$this->exceptionAnchors('catalog_get_exception_approved',$sha);if($anchors instanceof WP_Error)return $anchors;
  $revoked=$this->load('df_catalog_get_exception_revoked_'.$sha);if($revoked!==null)return self::error('exception_revoked_or_unavailable',409);
  $revocationAnchors=$this->exceptionAnchors('catalog_get_exception_revoked',$sha);if($revocationAnchors instanceof WP_Error)return $revocationAnchors;if($revocationAnchors!==[])return self::error('exception_revocation_evidence_missing',503);
  if($r===null){if($anchors!==[])return self::error('exception_evidence_missing',503);return Settings::safety_locked()?self::error('safety_locked',409):['mode'=>'ORDINARY_AUTHORIZATION','safety_exception_sha256'=>null];}
  if(!self::sealed($r)||($r['grant_sha256']??null)!==$sha||($r['capability']??null)!==PrintifyCatalogReadContract::CAPABILITY||($r['method']??null)!=='GET'||($r['site_sha256']??null)!==self::site()||count($anchors)!==1||($anchors[0]['sha256']??null)!==$r['sha256']||!$this->audited('catalog_get_exception_approved',$r['sha256'])||($r['grant_binding_sha256']??null)!==hash('sha256',self::json($grant['binding'])))return self::error('exception_evidence',503);
  if(!user_can($r['approved_by'],'manage_options')||!user_can($r['approved_by'],'manage_digiforge_connections')||!user_can($r['approved_by'],'manage_digiforge_pod'))return self::error('exception_owner_revoked',409);
  try{PrintifyCatalogGetSafetyExceptionContract::normalize($r['approval'],$grant['grant'],time());}catch(\Throwable){return self::error('exception_expired_or_invalid',409);}
  $posture=$this->protectedPosture();if($posture instanceof WP_Error)return $posture;
  if(wp_get_environment_type()!=='production'||!self::mainSite())return self::error('main_site_required',409);
  return ['mode'=>'CATALOG_GET_SAFETY_EXCEPTION','safety_exception_sha256'=>$r['sha256']];
 }
 private function exceptionAnchors(string $event,string $sha):array|WP_Error {
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND context LIKE %s LIMIT 2',$event,'catalog_read','%'.$wpdb->esc_like('"grant_sha256":"'.$sha.'"').'%'));if(self::databaseFailed()||!is_array($rows))return self::error('exception_audit_unavailable',503);$out=[];foreach($rows as $row){$r=json_decode($row,true);if(!is_array($r)||($r['grant_sha256']??null)!==$sha)return self::error('exception_audit_invalid',503);$out[]=$r;}return $out;
 }
 /** No permissive defaults: missing/corrupt/unavailable settings cannot admit the exception. */
 private function protectedPosture():true|WP_Error {
  global $wpdb;$expected=['activation_authorized'=>false,'automation_armed'=>false,'stop_all'=>true];$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT setting_key,setting_value,setting_type FROM '.Tables::settings().' WHERE setting_key IN (%s,%s,%s)',...array_keys($expected)),ARRAY_A);if(self::databaseFailed()||!is_array($rows)||count($rows)!==3)return self::error('exception_safety_evidence_unavailable',503);
  $seen=[];foreach($rows as $r){$key=$r['setting_key'];if(!array_key_exists($key,$expected)||isset($seen[$key])||$r['setting_type']!=='boolean'||json_decode($r['setting_value'],true)!==$expected[$key])return self::error('exception_safety_posture_changed',409);$seen[$key]=true;}return true;
 }
 /** Non-persisting projection source; immutable authorization/reservation audits still persist. */
 public function preview(string $sha,int $index,string $key):array|WP_Error {return $this->read($sha,$index,$key,false);}
 private function assigned(array $record):true|WP_Error {
  $shop=$record['binding']['shop_key']??null;
  if(!is_string($shop)||!\DigiForge\Security\ShopAccessPolicy::validShop($shop))return self::error('canonical_shop_binding_required',409);
  foreach(['manage_digiforge_connections','manage_digiforge_pod'] as $cap){$access=\DigiForge\Security\ShopAccess::authorize($shop,$cap);if($access instanceof WP_Error)return $access;}
  return true;
 }
 /** Authoritative grant admission; no caller-supplied authorization callback. */
 public function authorizeReservedAttempt(array $authority,array $request,string $token,bool $consume):true|WP_Error {
  if(count($authority)!==3||array_diff(array_keys($authority),['grant_sha256','request_index','request_sha256'])||!is_string($authority['grant_sha256']??null)||!is_int($authority['request_index']??null)||!is_string($authority['request_sha256']??null))return self::error('reserved_scope',403);
  $sha=$authority['grant_sha256'];$index=$authority['request_index'];$key=$authority['request_sha256'];
  return $this->locked($sha,function()use($sha,$index,$key,$request,$token,$consume){
   try{self::hash($key);}catch(\Throwable){return self::error('reserved_scope',403);}
   if(!self::reader()||!self::mainSite()||wp_get_environment_type()!=='production')return self::error('account_scope',403);
   $g=$this->grant($sha);if($g instanceof WP_Error)return $g;$access=$this->assigned($g);if($access instanceof WP_Error)return $access;
   if($g['grant']['account_id']!==get_current_user_id()||$index<0||!isset($g['grant']['requests'][$index])||self::json($request)!==self::json($g['grant']['requests'][$index]))return self::error('request_scope',403);
   $permission=$this->readPermission($sha,$g);if($permission instanceof WP_Error)return $permission;
   $attempts=$this->attempts($sha);if($attempts instanceof WP_Error)return $attempts;if(!in_array($key,$attempts,true))return self::error('reservation_required',403);
   $reserved=$this->load('df_catalog_read_request_'.$sha.'_'.$key);
   if(!is_array($reserved)||!self::sealed($reserved)||($reserved['request_index']??null)!==$index||($reserved['safety_exception_sha256']??null)!==$permission['safety_exception_sha256'])return self::error('reservation_scope',403);
   $integration=(new Repository())->find($g['grant']['integration_id']);if(!is_array($integration))return self::error('credential_evidence',503);
   $matched=false;foreach($integration['secrets']??[]as $secret){$name=$secret['secret_name']??'';if(!in_array($name,['personal_access_token','access_token'],true))continue;
    try{$fingerprint=CredentialVault::fingerprint($token,Repository::secretContext($g['grant']['integration_id'],$name));}catch(\Throwable){return self::error('credential_evidence',503);}
    if(is_string($secret['fingerprint']??null)&&hash_equals($secret['fingerprint'],$fingerprint))$matched=true;
   }if(!$matched)return self::error('credential_scope',403);
   $name='df_catalog_read_dispatch_'.$sha.'_'.$key;
   $dispatch=$this->load($name);if($dispatch instanceof WP_Error)return $dispatch;
   $anchors=$this->dispatchAnchors($sha,$key);if($anchors instanceof WP_Error)return $anchors;
   if(!$consume){return is_array($dispatch)&&self::sealed($dispatch)&&($dispatch['grant_sha256']??null)===$sha&&($dispatch['request_sha256']??null)===$key&&count($anchors)===1&&($anchors[0]['sha256']??null)===$dispatch['sha256']&&$this->audited('catalog_read_dispatched',$dispatch['sha256'])?true:self::error('dispatch_evidence',503);}
   // Either half of durable dispatch evidence consumes the intent permanently.
   // A missing option is uncertainty, never permission to repeat the request.
   if($dispatch!==null||$anchors!==[])return self::error('dispatch_replay_or_unavailable',409);
   return $this->transaction(function()use($name,$sha,$key){if($this->load($name)!==null)return self::error('dispatch_replay',409);
    $r=self::seal(['grant_sha256'=>$sha,'request_sha256'=>$key,'dispatched_at'=>gmdate('c')]);$saved=$this->put($name,$r,'catalog_read_dispatched');return $saved instanceof WP_Error?$saved:true;});
  });
 }
 public function read(string $sha,int $index,string $key,bool $persist=true):array|WP_Error {
  if(!self::reader())return self::error('account_scope',403);
  try{self::requestKey($key);}catch(\Throwable){return self::error('request_key',400);}
  return $this->locked($sha,function()use($sha,$index,$key,$persist){
   $record=$this->grant($sha);if($record instanceof WP_Error)return $record;$g=$record['grant'];if($g['account_id']!==get_current_user_id())return self::error('account_scope',403);
   if(!$persist&&($g['requests'][$index]['kind']??null)!=='variants')return self::error('preview_scope',403);
   if(!isset($g['requests'][$index])||$index<0)return self::error('request_scope',400);
   // A separate grant-bound catalog-GET approval never changes global lock semantics.
   $permission=$this->readPermission($sha,$record);if($permission instanceof WP_Error)return $permission;
   if(wp_get_environment_type()!=='production')return self::error('main_site_required',409);
   $requestSha=hash('sha256',$key);$receiptName='df_catalog_read_request_'.$sha.'_'.$requestSha;
   $reservation=$this->transaction(function()use($sha,$receiptName,$index,$g,$permission){
    if($this->load($receiptName)!==null)return self::error('request_replay',409);$attempts=$this->attempts($sha);if($attempts instanceof WP_Error)return $attempts;if(count($attempts)>=$g['max_requests'])return self::error('quota_exhausted',409);if(in_array(substr($receiptName,-64),$attempts,true))return self::error('request_replay',409);
    return $this->put($receiptName,self::seal(['grant_sha256'=>$sha,'request_sha256'=>substr($receiptName,-64),'request_index'=>$index,'safety_exception_sha256'=>$permission['safety_exception_sha256'],'reserved_at'=>gmdate('c')]),'catalog_read_reserved');
   });if($reservation instanceof WP_Error)return $reservation;
   // Recheck all authoritative boundaries immediately before decrypting. Failures consume the attempt.
   $current=$this->grant($sha);if($current instanceof WP_Error)return $current;$currentPermission=$this->readPermission($sha,$current);if($currentPermission instanceof WP_Error)return $currentPermission;if($currentPermission!==$permission)return self::error('safety_permission_changed',409);
   $access=$this->assigned($current);if($access instanceof WP_Error)return $access;
   $token=$this->credential($g['integration_id']);if($token instanceof WP_Error)return $token;
   try{$result=($this->client??new PrintifyCatalogClient())->scopedRead($token,$g['requests'][$index],['grant_sha256'=>$sha,'request_index'=>$index,'request_sha256'=>$requestSha]);}catch(\Throwable){$result=self::error('transport',502);}finally{$token='';}
   if($result instanceof WP_Error){if(!Logger::write('catalog_read_failed',['grant_sha256'=>$sha,'request_sha256'=>$requestSha,'code'=>$result->get_error_code()],'catalog_read',$sha))return self::error('audit_unavailable',503);return $result;}
   $current=$this->grant($sha);if($current instanceof WP_Error)return $current;$currentPermission=$this->readPermission($sha,$current);if($currentPermission instanceof WP_Error)return $currentPermission;if($currentPermission!==$permission||!self::reader())return self::error('approval_or_safety_changed',409);$access=$this->assigned($current);if($access instanceof WP_Error)return $access;
   $e=self::seal(['schema'=>1,'state'=>'DRAFT_UNVERIFIED','capability'=>PrintifyCatalogReadContract::CAPABILITY,'grant_sha256'=>$sha,'request_sha256'=>$requestSha,'request'=>$g['requests'][$index],'account_id'=>$g['account_id'],'scope'=>$record['binding']['scope'],'integration_id'=>$g['integration_id'],'environment'=>$record['binding']['environment'],'site_sha256'=>self::site(),'supplier_certified'=>false,'mutation_authorized'=>false,'safety_exception_sha256'=>$permission['safety_exception_sha256'],'read_admission_mode'=>$permission['mode']]+$result);
   if(!$persist)return self::publicEvidence($e);
   $stored=$this->transaction(fn()=>$this->put('df_catalog_read_evidence_'.$sha.'_'.$requestSha,$e,'catalog_read_evidence'));return $stored instanceof WP_Error?$stored:self::publicEvidence($e);
  });
 }
 public function evidence(string $sha,string $requestSha):array|WP_Error {
  if(!self::reader())return self::error('account_scope',403);try{self::hash($sha);self::hash($requestSha);}catch(\Throwable){return self::error('invalid_identity',400);}$g=$this->grant($sha);if($g instanceof WP_Error)return $g;if($g['grant']['account_id']!==get_current_user_id())return self::error('account_scope',403);$e=$this->load('df_catalog_read_evidence_'.$sha.'_'.$requestSha);if($e instanceof WP_Error)return $e;if($e===null||!self::sealed($e)||!$this->audited('catalog_read_evidence',$e['sha256'])||$e['grant_sha256']!==$sha||$e['request_sha256']!==$requestSha||hash('sha256',$e['response_body'])!==$e['response_sha256'])return self::error('evidence_unavailable',503);return self::publicEvidence($e);
 }
 private function grant(string $sha,bool $active=true):array|WP_Error {
  try{self::hash($sha);$r=$this->load('df_catalog_read_grant_'.$sha);if($r instanceof WP_Error)return $r;if($r===null||!self::sealed($r)||$r['sha256']!==$sha||(!self::mainSite()||$r['site_sha256']!==self::site())||!$this->audited('catalog_read_approved',$sha))return self::error('grant_evidence',409);
   if(!$active)return $r;$revoked=$this->load('df_catalog_read_revoked_'.$sha);if($revoked!==null)return self::error('revoked_or_unavailable',409);$revocationAnchors=$this->exceptionAnchors('catalog_read_revoked',$sha);if($revocationAnchors instanceof WP_Error)return $revocationAnchors;if($revocationAnchors!==[])return self::error('revocation_evidence_missing',503);$owner=$r['approved_by'];if(!user_can($owner,'manage_options')||!user_can($owner,'manage_digiforge_connections')||!user_can($owner,'manage_digiforge_pod')||strtotime($r['grant']['expires_at'])<=time())return self::error('expired_or_owner_revoked',409);$binding=$this->binding($r['grant']);if($binding instanceof WP_Error)return $binding;if(self::json($binding)!==self::json($r['binding']))return self::error('integration_or_scope_changed',409);return $r;
  }catch(\Throwable){return self::error('grant_evidence',503);}
 }
 private function binding(array $g,bool $ownership=true):array|WP_Error {
  try{$scope=BusinessScope::resolveConfigured($g);}catch(\Throwable){return self::error('shop_scope',409);}
  $i=(new Repository())->find($g['integration_id']);if($i instanceof WP_Error)return $i;if(!is_array($i)||$i['provider']!=='printify'||$i['status']!=='CONFIGURED'||$i['enabled']!==true)return self::error('integration_scope',409);
  $configured=$i['config']['catalog_read_scope']??null;$wanted=array_intersect_key($g,array_flip(['business_id','store_id','product_program']));if(!is_array($configured)||self::json($configured)!==self::json($wanted))return self::error('integration_shop_binding_required',409);
  $shop=$i['config']['shop_key']??null;if(!is_string($shop)||!\DigiForge\Security\ShopAccessPolicy::validShop($shop))return self::error('canonical_shop_binding_required',409);
  if(!$ownership)return ['shop_key'=>$shop,'scope'=>$scope,'environment'=>$i['environment'],'integration_sha256'=>hash('sha256',self::json($i))];
  $relationship=(new ScopedProviderConnection())->verified($shop,$g['integration_id'],PrintifyCatalogReadContract::CAPABILITY);if($relationship instanceof WP_Error)return ($relationship->get_error_data()['status']??409)===403?self::error('account_scope',403):$relationship;
  return ['shop_key'=>$shop,'scope'=>$scope,'environment'=>$i['environment'],'integration_sha256'=>hash('sha256',self::json($i)),'ownership_binding_sha256'=>$relationship['binding_hash']];
 }
 /** Both immutable reservation rows and their audit anchors are required; no resettable quota counter. */
 private function attempts(string $sha):array|WP_Error {
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT option_name,option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s LIMIT 11',$wpdb->esc_like('df_catalog_read_request_'.$sha.'_').'%'),ARRAY_A);if(self::databaseFailed()||!is_array($rows)||count($rows)>10)return self::error('quota_evidence',503);
  $wpdb->last_error='';$audits=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND context LIKE %s LIMIT 11','catalog_read_reserved','catalog_read','%'.$wpdb->esc_like('"grant_sha256":"'.$sha.'"').'%'));if(self::databaseFailed()||!is_array($audits)||count($audits)!==count($rows))return self::error('quota_evidence',503);
  $anchors=[];foreach($audits as $a){$v=json_decode($a,true);if(!is_array($v)||($v['grant_sha256']??null)!==$sha||!is_string($v['request_sha256']??null)||isset($anchors[$v['request_sha256']]))return self::error('quota_evidence',503);$anchors[$v['request_sha256']]=$v['sha256']??null;}
  $keys=[];foreach($rows as $row){$r=json_decode($row['option_value'],true);if(!is_array($r)||!self::sealed($r)||($r['grant_sha256']??null)!==$sha||!is_string($r['request_sha256']??null)||$row['option_name']!=='df_catalog_read_request_'.$sha.'_'.$r['request_sha256']||($anchors[$r['request_sha256']]??null)!==$r['sha256'])return self::error('quota_evidence',503);$keys[]=$r['request_sha256'];}return $keys;
 }
 /** At most one dispatch anchor for this exact grant/request pair. */
 private function dispatchAnchors(string $sha,string $key):array|WP_Error {
  global $wpdb;$wpdb->last_error='';
  $rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND context LIKE %s AND context LIKE %s LIMIT 2','catalog_read_dispatched','catalog_read','%'.$wpdb->esc_like('"grant_sha256":"'.$sha.'"').'%','%'.$wpdb->esc_like('"request_sha256":"'.$key.'"').'%'));
  if(self::databaseFailed()||!is_array($rows)||count($rows)>1)return self::error('dispatch_audit_unavailable',503);
  $out=[];foreach($rows as $row){$r=json_decode($row,true);if(!is_array($r)||($r['grant_sha256']??null)!==$sha||($r['request_sha256']??null)!==$key||!is_string($r['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['sha256']))return self::error('dispatch_audit_invalid',503);$out[]=$r;}return $out;
 }
 private function credential(int $id):string|WP_Error {
  global $wpdb;foreach(['personal_access_token','access_token']as $name){$wpdb->last_error='';$cipher=$wpdb->get_var($wpdb->prepare('SELECT ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id=%d AND secret_name=%s LIMIT 1',$id,$name));if(self::databaseFailed())return self::error('credential_evidence',503);if(is_string($cipher)&&$cipher!==''){try{return CredentialVault::decrypt($cipher,Repository::secretContext($id,$name));}catch(\Throwable){return self::error('credential_unavailable',503);}finally{$cipher='';}}}return self::error('credential_missing',409);
 }
 private function load(string $name):array|WP_Error|null {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$name));if(self::databaseFailed())return self::error('storage_unavailable',503);if($raw===null)return null;if(!is_string($raw)||strlen($raw)>2200000)return self::error('storage_corrupt',503);$r=json_decode($raw,true,40);return is_array($r)?$r:self::error('storage_corrupt',503);}
 private function put(string $name,array $r,string $event=''):array|WP_Error {global $wpdb;$json=self::json($r);if(strlen($json)>2200000||$this->load($name)!==null)return self::error('immutable_or_unavailable',409);$wpdb->last_error='';if($wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>$json,'autoload'=>'no'])!==1||self::databaseFailed())return self::error('storage_failed',503);if($event!==''&&!Logger::write($event,['sha256'=>$r['sha256'],'grant_sha256'=>$r['grant_sha256']??$r['sha256'],'request_sha256'=>$r['request_sha256']??null,'capability'=>PrintifyCatalogReadContract::CAPABILITY],'catalog_read',$r['sha256']))return self::error('audit_unavailable',503);return $r;}
 private function audited(string $event,string $sha):bool {global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s',$event,'catalog_read',$sha));return !self::databaseFailed()&&is_array($rows)&&(bool)array_filter($rows,static fn($r)=>(json_decode($r,true)['sha256']??null)===$sha);}
 private function transaction(callable $fn):mixed {global $wpdb;$wpdb->last_error='';$engines=$wpdb->get_col($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',$wpdb->options,Tables::audit_log()));if(self::databaseFailed()||!is_array($engines)||count($engines)!==2||array_filter($engines,static fn($e)=>strtoupper($e)!=='INNODB'))return self::error('transactional_storage_required',503);if($wpdb->query('START TRANSACTION')===false)return self::error('transaction_failed',503);try{$r=$fn();if($r instanceof WP_Error){if($wpdb->query('ROLLBACK')===false)return self::error('rollback_unknown',503);return $r;}if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return self::error('commit_unknown',503);}return $r;}catch(\Throwable){$wpdb->query('ROLLBACK');return self::error('persistence_failed',503);}}
 private function locked(string $sha,callable $fn):mixed {try{self::hash($sha);}catch(\Throwable){return self::error('invalid_identity',400);}global $wpdb;$name='df-catalog-read-'.substr($sha,0,45);$wpdb->last_error='';if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name))!=='1'||self::databaseFailed())return self::error('busy_or_unavailable',409);try{$result=$fn();}catch(\Throwable){$result=self::error('failed',503);}$wpdb->last_error='';if((string)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name))!=='1'||self::databaseFailed())return self::error('release_unknown',503);return $result;}
 /** Reads WPDB's mutable error after a query; no error condition is suppressed.
  * @phpstan-impure
  */
 private static function databaseFailed():bool {global $wpdb;return $wpdb->last_error!=='';}
 private static function mainSite():bool {return defined('DIGIFORGE_MAIN_SITE_URL')&&is_string(DIGIFORGE_MAIN_SITE_URL)&&DIGIFORGE_MAIN_SITE_URL!==''&&hash_equals(rtrim(DIGIFORGE_MAIN_SITE_URL,'/'),rtrim(site_url(),'/'));}
 private static function site():string {return hash('sha256',get_current_blog_id().'|'.site_url());}
 private static function publicEvidence(array $r):array {unset($r['response_body']);return json_decode(self::json($r),true,40,JSON_THROW_ON_ERROR);}
 private static function requestKey(string $key):void {if(!preg_match('/^[A-Za-z0-9_-]{8,100}$/D',$key))throw new \InvalidArgumentException();}
 private static function hash(string $s):void {if(!preg_match('/^[a-f0-9]{64}$/D',$s))throw new \InvalidArgumentException();}
 private static function json(array $v):string {$sort=function(array $a)use(&$sort):array{foreach($a as &$v)if(is_array($v))$v=$sort($v);unset($v);if(!array_is_list($a))ksort($a);return $a;};return json_encode($sort($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
 private static function seal(array $r):array {$r['sha256']=hash('sha256',self::json($r));return $r;}
 private static function sealed(array $r):bool {$s=$r['sha256']??'';unset($r['sha256']);return is_string($s)&&hash_equals($s,hash('sha256',self::json($r)));}
 private static function error(string $code,int $status):WP_Error {return new WP_Error('catalog_read_'.$code,'Explicit current owner catalog-read approval and existing safety interlocks are required.',['status'=>$status,'mutation_authorized'=>false,'retry_permitted'=>false]);}
}
