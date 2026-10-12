<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository;
use DigiForge\Security\Logger;
use WP_Error;

/** Append-only local grants/receipts. This class never enables any capability or catalog route. */
final class PrintifyCatalogReadRepository {
 public function __construct(private ?PrintifyCatalogClient $client=null) {}
 public static function owner():bool {return get_current_user_id()>0&&current_user_can('manage_options')&&current_user_can('manage_digiforge_connections')&&current_user_can('manage_digiforge_pod');}
 public static function reader():bool {return get_current_user_id()>0&&current_user_can('manage_digiforge_connections')&&current_user_can('manage_digiforge_pod');}
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
 public function read(string $sha,int $index,string $key):array|WP_Error {
  if(!self::reader())return self::error('account_scope',403);
  try{self::requestKey($key);}catch(\Throwable){return self::error('request_key',400);}
  return $this->locked($sha,function()use($sha,$index,$key){
   $record=$this->grant($sha);if($record instanceof WP_Error)return $record;$g=$record['grant'];if($g['account_id']!==get_current_user_id())return self::error('account_scope',403);
   if(!isset($g['requests'][$index])||$index<0)return self::error('request_scope',400);
   // Existing STOP ALL / automation / external activation interlocks always win.
   if(Settings::safety_locked())return self::error('safety_locked',409);
   if(wp_get_environment_type()!=='production')return self::error('main_site_required',409);
   $requestSha=hash('sha256',$key);$receiptName='df_catalog_read_request_'.$sha.'_'.$requestSha;
   $reservation=$this->transaction(function()use($sha,$receiptName,$index,$g){
    if($this->load($receiptName)!==null)return self::error('request_replay',409);$attempts=$this->attempts($sha);if($attempts instanceof WP_Error)return $attempts;if(count($attempts)>=$g['max_requests'])return self::error('quota_exhausted',409);if(in_array(substr($receiptName,-64),$attempts,true))return self::error('request_replay',409);
    return $this->put($receiptName,self::seal(['grant_sha256'=>$sha,'request_sha256'=>substr($receiptName,-64),'request_index'=>$index,'reserved_at'=>gmdate('c')]),'catalog_read_reserved');
   });if($reservation instanceof WP_Error)return $reservation;
   // Recheck all authoritative boundaries immediately before decrypting. Failures consume the attempt.
   $current=$this->grant($sha);if($current instanceof WP_Error)return $current;if(Settings::safety_locked())return self::error('safety_locked',409);
   $token=$this->credential($g['integration_id']);if($token instanceof WP_Error)return $token;
   try{$result=($this->client??new PrintifyCatalogClient())->scopedRead($token,$g['requests'][$index]);}catch(\Throwable){$result=self::error('transport',502);}finally{$token='';}
   if($result instanceof WP_Error){if(!Logger::write('catalog_read_failed',['grant_sha256'=>$sha,'request_sha256'=>$requestSha,'code'=>$result->get_error_code()],'catalog_read',$sha))return self::error('audit_unavailable',503);return $result;}
   if(strtotime($g['expires_at'])<=time()||Settings::safety_locked())return self::error('approval_or_safety_changed',409);
   $e=self::seal(['schema'=>1,'state'=>'DRAFT_UNVERIFIED','capability'=>PrintifyCatalogReadContract::CAPABILITY,'grant_sha256'=>$sha,'request_sha256'=>$requestSha,'request'=>$g['requests'][$index],'account_id'=>$g['account_id'],'scope'=>$record['binding']['scope'],'integration_id'=>$g['integration_id'],'environment'=>$record['binding']['environment'],'site_sha256'=>self::site(),'supplier_certified'=>false,'mutation_authorized'=>false]+$result);
   $stored=$this->transaction(fn()=>$this->put('df_catalog_read_evidence_'.$sha.'_'.$requestSha,$e,'catalog_read_evidence'));return $stored instanceof WP_Error?$stored:self::publicEvidence($e);
  });
 }
 public function evidence(string $sha,string $requestSha):array|WP_Error {
  if(!self::reader())return self::error('account_scope',403);try{self::hash($sha);self::hash($requestSha);}catch(\Throwable){return self::error('invalid_identity',400);}$g=$this->grant($sha);if($g instanceof WP_Error)return $g;if($g['grant']['account_id']!==get_current_user_id())return self::error('account_scope',403);$e=$this->load('df_catalog_read_evidence_'.$sha.'_'.$requestSha);if($e instanceof WP_Error)return $e;if($e===null||!self::sealed($e)||!$this->audited('catalog_read_evidence',$e['sha256'])||$e['grant_sha256']!==$sha||$e['request_sha256']!==$requestSha||hash('sha256',$e['response_body'])!==$e['response_sha256'])return self::error('evidence_unavailable',503);return self::publicEvidence($e);
 }
 private function grant(string $sha,bool $active=true):array|WP_Error {
  try{self::hash($sha);$r=$this->load('df_catalog_read_grant_'.$sha);if($r instanceof WP_Error)return $r;if($r===null||!self::sealed($r)||$r['sha256']!==$sha||(!self::mainSite()||$r['site_sha256']!==self::site())||!$this->audited('catalog_read_approved',$sha))return self::error('grant_evidence',409);
   if(!$active)return $r;$revoked=$this->load('df_catalog_read_revoked_'.$sha);if($revoked!==null)return self::error('revoked_or_unavailable',409);$owner=$r['approved_by'];if(!user_can($owner,'manage_options')||!user_can($owner,'manage_digiforge_connections')||!user_can($owner,'manage_digiforge_pod')||strtotime($r['grant']['expires_at'])<=time())return self::error('expired_or_owner_revoked',409);$binding=$this->binding($r['grant']);if($binding instanceof WP_Error)return $binding;if(self::json($binding)!==self::json($r['binding']))return self::error('integration_or_scope_changed',409);return $r;
  }catch(\Throwable){return self::error('grant_evidence',503);}
 }
 private function binding(array $g):array|WP_Error {
  try{$scope=BusinessScope::resolveConfigured($g);}catch(\Throwable){return self::error('shop_scope',409);}
  $i=(new Repository())->find($g['integration_id']);if($i instanceof WP_Error)return $i;if(!is_array($i)||$i['provider']!=='printify'||$i['status']!=='CONFIGURED'||$i['enabled']!==true)return self::error('integration_scope',409);
  $configured=$i['config']['catalog_read_scope']??null;$wanted=array_intersect_key($g,array_flip(['business_id','store_id','product_program']));if(!is_array($configured)||self::json($configured)!==self::json($wanted))return self::error('integration_shop_binding_required',409);
  return ['scope'=>$scope,'environment'=>$i['environment'],'integration_sha256'=>hash('sha256',self::json($i))];
 }
 /** Both immutable reservation rows and their audit anchors are required; no resettable quota counter. */
 private function attempts(string $sha):array|WP_Error {
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT option_name,option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s LIMIT 11',$wpdb->esc_like('df_catalog_read_request_'.$sha.'_').'%'),ARRAY_A);if($wpdb->last_error!==''||!is_array($rows)||count($rows)>10)return self::error('quota_evidence',503);
  $wpdb->last_error='';$audits=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND context LIKE %s LIMIT 11','catalog_read_reserved','catalog_read','%'.$wpdb->esc_like('"grant_sha256":"'.$sha.'"').'%'));if($wpdb->last_error!==''||!is_array($audits)||count($audits)!==count($rows))return self::error('quota_evidence',503);
  $anchors=[];foreach($audits as $a){$v=json_decode($a,true);if(!is_array($v)||($v['grant_sha256']??null)!==$sha||!is_string($v['request_sha256']??null)||isset($anchors[$v['request_sha256']]))return self::error('quota_evidence',503);$anchors[$v['request_sha256']]=$v['sha256']??null;}
  $keys=[];foreach($rows as $row){$r=json_decode($row['option_value'],true);if(!is_array($r)||!self::sealed($r)||($r['grant_sha256']??null)!==$sha||!is_string($r['request_sha256']??null)||$row['option_name']!=='df_catalog_read_request_'.$sha.'_'.$r['request_sha256']||($anchors[$r['request_sha256']]??null)!==$r['sha256'])return self::error('quota_evidence',503);$keys[]=$r['request_sha256'];}return $keys;
 }
 private function credential(int $id):string|WP_Error {
  global $wpdb;foreach(['personal_access_token','access_token']as $name){$wpdb->last_error='';$cipher=$wpdb->get_var($wpdb->prepare('SELECT ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id=%d AND secret_name=%s LIMIT 1',$id,$name));if($wpdb->last_error!=='')return self::error('credential_evidence',503);if(is_string($cipher)&&$cipher!==''){try{return CredentialVault::decrypt($cipher,Repository::secretContext($id,$name));}catch(\Throwable){return self::error('credential_unavailable',503);}finally{$cipher='';}}}return self::error('credential_missing',409);
 }
 private function load(string $name):array|WP_Error|null {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$name));if($wpdb->last_error!=='')return self::error('storage_unavailable',503);if($raw===null)return null;if(!is_string($raw)||strlen($raw)>2200000)return self::error('storage_corrupt',503);$r=json_decode($raw,true,40);return is_array($r)?$r:self::error('storage_corrupt',503);}
 private function put(string $name,array $r,string $event=''):array|WP_Error {global $wpdb;$json=self::json($r);if(strlen($json)>2200000||$this->load($name)!==null)return self::error('immutable_or_unavailable',409);$wpdb->last_error='';if($wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>$json,'autoload'=>'no'])!==1||$wpdb->last_error!=='')return self::error('storage_failed',503);if($event!==''&&!Logger::write($event,['sha256'=>$r['sha256'],'grant_sha256'=>$r['grant_sha256']??$r['sha256'],'request_sha256'=>$r['request_sha256']??null,'capability'=>PrintifyCatalogReadContract::CAPABILITY],'catalog_read',$r['sha256']))return self::error('audit_unavailable',503);return $r;}
 private function audited(string $event,string $sha):bool {global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s',$event,'catalog_read',$sha));return $wpdb->last_error===''&&is_array($rows)&&(bool)array_filter($rows,static fn($r)=>(json_decode($r,true)['sha256']??null)===$sha);}
 private function transaction(callable $fn):mixed {global $wpdb;$wpdb->last_error='';$engines=$wpdb->get_col($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',$wpdb->options,Tables::audit_log()));if($wpdb->last_error!==''||!is_array($engines)||count($engines)!==2||array_filter($engines,static fn($e)=>strtoupper($e)!=='INNODB'))return self::error('transactional_storage_required',503);if($wpdb->query('START TRANSACTION')===false)return self::error('transaction_failed',503);try{$r=$fn();if($r instanceof WP_Error){if($wpdb->query('ROLLBACK')===false)return self::error('rollback_unknown',503);return $r;}if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return self::error('commit_unknown',503);}return $r;}catch(\Throwable){$wpdb->query('ROLLBACK');return self::error('persistence_failed',503);}}
 private function locked(string $sha,callable $fn):mixed {try{self::hash($sha);}catch(\Throwable){return self::error('invalid_identity',400);}global $wpdb;$name='df-catalog-read-'.substr($sha,0,45);$wpdb->last_error='';if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name))!=='1'||$wpdb->last_error!=='')return self::error('busy_or_unavailable',409);try{$result=$fn();}catch(\Throwable){$result=self::error('failed',503);}$wpdb->last_error='';if((string)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name))!=='1'||$wpdb->last_error!=='')return self::error('release_unknown',503);return $result;}
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
