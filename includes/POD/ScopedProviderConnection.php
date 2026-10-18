<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\ProviderConnectionSchema;
use DigiForge\Database\Tables;
use DigiForge\Integrations\Repository;
use DigiForge\Listings\Validator;
use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** DF-OWN-001 relationships restrict authority; independent provider/safety grants remain mandatory. */
final class ScopedProviderConnection
{
 private const CAPABILITY='printify.catalog.read';
 private const FIELDS=['integration_id','capability','source_sha256','evidence_reference','observed_at'];
 public function propose(string $shop,array $input,int $effectiveAt,int $expiresAt,string $requestId,int $revision):array|WP_Error {
  if(count($input)!==count(self::FIELDS)||array_diff(array_keys($input),self::FIELDS))return self::error('invalid_input');
  if(!is_int($input['integration_id'])||!is_string($input['capability'])||!is_string($input['source_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$input['source_sha256'])||!is_string($input['evidence_reference'])||!preg_match('#^local://[A-Za-z0-9/_.:-]{1,240}$#D',$input['evidence_reference'])||!is_string($input['observed_at'])||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$input['observed_at']))return self::error('invalid_evidence');
  $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',str_replace('Z','+00:00',$input['observed_at']));$errors=\DateTimeImmutable::getLastErrors();
  if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->getTimestamp()>time()||$effectiveAt<$date->getTimestamp()||$expiresAt<=$effectiveAt)return self::error('invalid_evidence');
  $current=$this->snapshot($shop,$input['integration_id'],$input['capability']);if(is_wp_error($current))return $current;
  $payload=$current+$input+['contract'=>'DF-OWN-001-v1.0','provider_identity_verified'=>false,'external_execution_authorized'=>false];
  $key=self::key($shop,$input['integration_id'],$input['capability']);
  return $this->transaction(function()use($shop,$key,$payload,$effectiveAt,$expiresAt,$requestId,$revision){
   $state=ReviewedLocalEvidence::propose('ownership',$shop,$key,$payload,$effectiveAt,$expiresAt,$requestId,$revision);if(is_wp_error($state))return $state;
   global $wpdb;$hash=$state['proposal_hash'];$wpdb->last_error='';
   $ok=$wpdb->insert(ProviderConnectionSchema::table(),['binding_hash'=>$hash,'review_key'=>$key,'integration_id'=>$payload['integration_id'],'business_id'=>$payload['business_id'],'store_id'=>$payload['store_id'],'program_id'=>$payload['program_id'],'shop_key'=>$shop,'provider'=>$payload['provider'],'capability'=>$payload['capability'],'payload'=>self::json($payload),'created_at'=>gmdate('Y-m-d H:i:s')]);
   return $ok===1&&!self::databaseFailed()?$state:self::error('persistence',503);
  });
 }
 public function decide(string $shop,int $integration,string $capability,int $revision,string $hash,string $decision,string $requestId):array|WP_Error {
  if($decision==='APPROVED'){$valid=$this->status($shop,$integration,$capability);if(($valid['status']??'')!=='PENDING_OWNER_APPROVAL'||($valid['binding_hash']??null)!==$hash)return self::error('stale_or_missing');}
  return ReviewedLocalEvidence::decide('ownership',$shop,self::key($shop,$integration,$capability),$revision,$hash,$decision,$requestId);
 }
 /** Local relationship verification only: NEVER a network grant or supplier certification. */
 public function verified(string $shop,int $integration,string $capability):array|WP_Error {
  $result=$this->status($shop,$integration,$capability);
  return $result['status']==='VERIFIED_LOCAL_BINDING'?$result:self::error(strtolower($result['status']),$result['status']==='ACCESS_DENIED'?403:409);
 }
 public function status(string $shop,int $integration,string $capability=self::CAPABILITY):array {
  $base=['status'=>'UNKNOWN','shop_key'=>$shop,'integration_id'=>$integration,'capability'=>$capability,'provider_identity_verified'=>false,'external_execution_authorized'=>false,'read_only'=>true];
  $access=ShopAccess::authorize($shop,'manage_digiforge_connections');if(is_wp_error($access))return array_replace($base,['status'=>'ACCESS_DENIED']);
  if(in_array($shop,['standard_pod','jewelry'],true))return array_replace($base,['status'=>'DEFERRED_SHOP']);
  if($shop==='digital'&&$capability===self::CAPABILITY)return array_replace($base,['status'=>'NOT_APPLICABLE']);
  $key=self::key($shop,$integration,$capability);$state=ReviewedLocalEvidence::read('ownership',$shop,$key);
  if(is_wp_error($state))return array_replace($base,['status'=>'INVALID_EVIDENCE']);
  if($state===null)return $base;
  $base['binding_hash']=$state['proposal_hash'];$base['review_revision']=$state['revision'];
  if($state['status']==='REVOKED')return array_replace($base,['status'=>'REVOKED']);
  if($state['expires_at']<=time()||$state['effective_at']>time())return array_replace($base,['status'=>'EXPIRED_OR_NOT_EFFECTIVE']);
  $current=$this->snapshot($shop,$integration,$capability);if(is_wp_error($current))return array_replace($base,['status'=>'DRIFTED']);
  foreach($current as $field=>$value)if(($state['payload'][$field]??null)!==$value)return array_replace($base,['status'=>'DRIFTED']);
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ProviderConnectionSchema::table().' WHERE review_key=%s AND binding_hash=%s LIMIT 2',$key,$state['proposal_hash']),ARRAY_A);
  if(self::databaseFailed()||!is_array($rows)||count($rows)!==1)return array_replace($base,['status'=>'MISSING_OR_AMBIGUOUS_EVIDENCE']);
  $row=$rows[0];$payload=$state['payload'];
  foreach(['integration_id','business_id','store_id','program_id']as $field)if((int)$row[$field]!==$payload[$field])return array_replace($base,['status'=>'INVALID_EVIDENCE']);
  foreach(['shop_key','provider','capability']as $field)if($row[$field]!==$payload[$field])return array_replace($base,['status'=>'INVALID_EVIDENCE']);
  if($row['payload']!==self::json($payload))return array_replace($base,['status'=>'INVALID_EVIDENCE']);
  if($state['status']!=='APPROVED')return array_replace($base,['status'=>$state['status']==='PENDING'?'PENDING_OWNER_APPROVAL':'REJECTED']);
  $approved=ReviewedLocalEvidence::approved('ownership',$shop,$key);if(is_wp_error($approved))return array_replace($base,['status'=>'INVALID_APPROVAL']);
  return array_replace($base,['status'=>'VERIFIED_LOCAL_BINDING','business_id'=>$payload['business_id'],'store_id'=>$payload['store_id'],'program_id'=>$payload['program_id'],'environment'=>$payload['environment'],'provider'=>$payload['provider'],'review_revision'=>$state['revision'],'approval_version'=>$state['version'],'effective_at'=>$state['effective_at'],'expires_at'=>$state['expires_at'],'external_shop_reference'=>$payload['external_shop_reference'],'evidence_sha256'=>$payload['source_sha256']]);
 }
 /** Scope the collection before its bounded window; never return global connection metadata. */
 public function forShop(string $shop):array {
  $base=['query_state'=>'UNAVAILABLE','items'=>[],'read_only'=>true,'external_execution_authorized'=>false];
  if(is_wp_error(ShopAccess::authorize($shop,'manage_digiforge_connections')))return array_replace($base,['query_state'=>'ACCESS_DENIED']);
  if(in_array($shop,['standard_pod','jewelry'],true))return array_replace($base,['query_state'=>'DEFERRED_SHOP']);
  if($shop==='digital')return array_replace($base,['query_state'=>'NOT_APPLICABLE']);
  global $wpdb;$wpdb->last_error='';$ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM ".Tables::integrations()." WHERE provider=%s AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(config),config,'{}'),'$.shop_key'))=%s ORDER BY id DESC LIMIT 51",'printify',$shop));
  if(self::databaseFailed()||!is_array($ids))return $base;
  $items=[];foreach(array_slice($ids,0,50)as $id)$items[]=$this->status($shop,(int)$id);
  return array_replace($base,['query_state'=>'AVAILABLE','items'=>$items,'window_limited'=>count($ids)>50]);
 }
 /** Current explicit configuration and registry ancestry; no credential decryption. */
 private function snapshot(string $shop,int $integration,string $capability):array|WP_Error {
  if($shop!=='personalized_pod'||$integration<1||$capability!==self::CAPABILITY)return self::error('unsupported_scope');
  $access=ShopAccess::authorize($shop,'manage_digiforge_connections');if(is_wp_error($access))return $access;
  $i=(new Repository())->find($integration);if(!is_array($i)||$i['provider']!=='printify'||$i['status']!=='CONFIGURED')return self::error('integration');
  $c=$i['config'];if(($c['shop_key']??null)!==$shop||!is_string($c['account_reference']??null)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D',$c['account_reference'])||!is_string($c['shop_id']??null)||!preg_match('/^[1-9][0-9]{0,18}$/D',$c['shop_id'])||!is_array($c['catalog_read_scope']??null)||($c['catalog_read_scope']['product_program']??null)!=='PERSONALIZED_POD'||empty($i['secrets']))return self::error('identity_or_material_missing');
  try{$scope=BusinessScope::resolveConfigured($c['catalog_read_scope']);}catch(\Throwable){return self::error('registry');}
  // Explicit relation identity cannot be inferred from shared material. A duplicate external shop is ambiguous.
  global $wpdb;$wpdb->last_error='';$others=$wpdb->get_results($wpdb->prepare('SELECT id,config FROM '.Tables::integrations().' WHERE provider=%s AND environment=%s AND status=%s LIMIT 1001','printify',$i['environment'],'CONFIGURED'),ARRAY_A);
  if(self::databaseFailed()||!is_array($others)||count($others)>1000)return self::error('registry_unavailable',503);
  foreach($others as $other){if((int)$other['id']===$integration)continue;$o=json_decode($other['config'],true);if(!is_array($o)||($o['shop_id']??null)===$c['shop_id'])return self::error('ambiguous_identity');}
  $wpdb->last_error='';$history=$wpdb->get_results($wpdb->prepare('SELECT id,event_type,actor_id,context FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s ORDER BY id ASC LIMIT 1001','integration',(string)$integration),ARRAY_A);
  if(self::databaseFailed()||!is_array($history)||count($history)<1||count($history)>1000)return self::error('integration_history_unavailable',503);
  $registry=[];foreach([[Tables::businesses(),$scope['business_id']],[Tables::stores(),$scope['store_id']],[Tables::product_programs(),$scope['product_program_id']]]as [$table,$id]){$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',$id),ARRAY_A);if(self::databaseFailed()||!is_array($row))return self::error('registry_unavailable',503);$registry[]=$row;}
  $installation=hash('sha256',self::json(['site'=>rtrim(site_url(),'/'),'blog'=>get_current_blog_id(),'environment'=>wp_get_environment_type(),'main_site'=>defined('DIGIFORGE_MAIN_SITE_URL')?DIGIFORGE_MAIN_SITE_URL:'']));
  return ['shop_key'=>$shop,'integration_id'=>$integration,'capability'=>$capability,'provider'=>'printify','environment'=>$i['environment'],'installation_sha256'=>$installation,'business_id'=>$scope['business_id'],'store_id'=>$scope['store_id'],'program_id'=>$scope['product_program_id'],'product_program'=>$scope['product_program'],'external_account_reference'=>$c['account_reference'],'external_shop_reference'=>$c['shop_id'],'integration_history_sha256'=>hash('sha256',self::json($history)),'registry_snapshot_sha256'=>hash('sha256',self::json($registry)),'integration_snapshot_sha256'=>hash('sha256',self::json($i)),'connection_material_sha256'=>hash('sha256',self::json($i['secrets']))];
 }
 private static function key(string $shop,int $integration,string $capability):string {return hash('sha256',self::json(['DF-OWN-001',$shop,$integration,$capability]));}
 private static function json(array $a):string {return Validator::canonicalJson($a);}
 /** @phpstan-impure */
 private static function databaseFailed():bool {global $wpdb;return $wpdb->last_error!=='';}
 private static function error(string $code,int $status=409):WP_Error {return new WP_Error('provider_connection_'.$code,'Current exact independently reviewed scoped connection evidence is required.',['status'=>$status,'external_execution_authorized'=>false]);}
 private function transaction(callable $work):array|WP_Error {
  global $wpdb;$wpdb->last_error='';$tables=[$wpdb->options,Tables::audit_log(),ProviderConnectionSchema::table()];
  foreach($tables as $table){$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(self::databaseFailed()||strtoupper((string)$engine)!=='INNODB')return self::error('transaction_unavailable',503);}
  $active=$wpdb->get_var('SELECT @@session.in_transaction');if(self::databaseFailed()||!in_array((string)$active,['0','1'],true))return self::error('transaction_unavailable',503);
  $nested=(string)$active==='1';$save='df_connection_'.bin2hex(random_bytes(8));$start=$nested?'SAVEPOINT '.$save:'START TRANSACTION';$end=$nested?'RELEASE SAVEPOINT '.$save:'COMMIT';$rollback=$nested?'ROLLBACK TO SAVEPOINT '.$save:'ROLLBACK';
  if($wpdb->query($start)===false)return self::error('transaction_unavailable',503);
  try{$result=$work();if(is_wp_error($result)){if($wpdb->query($rollback)===false)return self::error('rollback_unknown',503);if($nested)$wpdb->query('RELEASE SAVEPOINT '.$save);return $result;}if($wpdb->query($end)===false){$wpdb->query($rollback);return self::error('commit_unknown',503);}return $result;}catch(\Throwable){$wpdb->query($rollback);if($nested)$wpdb->query('RELEASE SAVEPOINT '.$save);return self::error('persistence',503);}
 }
}
