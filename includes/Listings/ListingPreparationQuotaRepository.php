<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\AI\AiRunContext;
use DigiForge\AI\ShopAiGovernanceRepository;
use DigiForge\Database\Tables;
use WP_Error;
/** Optional reviewed quantity plan for deterministic local work, with no AI charge. */
final class ListingPreparationQuotaRepository {
 public static function fingerprint(string $shop,string $environment,int $productId,int $versionId,int $bundleId,string $bundleHash,string $key,?array $run):string {
  return hash('sha256',(string)wp_json_encode([$shop,$environment,$productId,$versionId,$bundleId,$bundleHash,$key,$run]));
 }
 public function reserve(string $shop,string $environment,array $version,array $bundle,string $key,?array $run=null):array|WP_Error {
  if(!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true)||!in_array($environment,['sandbox','production'],true))return $this->error('scope_invalid','Exact local shop and environment are required.');
  if($run!==null){$normalized=AiRunContext::normalize((string)($run['run_id']??''),(string)($run['started_at']??''));if(is_wp_error($normalized))return $normalized;$run=$normalized;}
  $productId=(int)($version['product_id']??0);$versionId=(int)($version['id']??0);$bundleId=(int)($bundle['id']??0);
  if($productId<1||$versionId<1||$bundleId<1||trim($key)==='')return $this->error('identity_invalid','Exact product version, bundle and preparation key are required.');
  $attribution=(new \DigiForge\AI\MonetaryReservationRepository())->validateAttribution($shop,['product_id'=>$productId,'order_id'=>0]);if(is_wp_error($attribution))return $attribution;
  $fingerprint=self::fingerprint($shop,$environment,$productId,$versionId,$bundleId,hash('sha256',(string)wp_json_encode([$bundle['checksum_sha256']??'',$bundle['manifest']??''])),$key,$run);
  $usageKey='local-listing-'.hash('sha256',$shop.'|'.$environment.'|'.$key);$workflow='listing_factory_'.$environment;$model='local_deterministic_listing:'.$fingerprint;
  global $wpdb;$lock=ShopAiGovernanceRepository::generationLockName($shop);$wpdb->last_error='';$locked=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
  if(!empty($wpdb->last_error)||(string)$locked!=='1')return $this->error('busy','Listing preparation requires exclusive shop quantity accounting.');
  try{$result=$this->reserveLocked($shop,$environment,$productId,$usageKey,$workflow,$model,$run);}catch(\Throwable){$result=$this->error('uncertain','Listing quota persistence is uncertain; no automatic retry.');}
  finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$releaseFailed=!empty($wpdb->last_error)||(string)$released!=='1';}
  return $releaseFailed?$this->error('lock_uncertain','Listing quota lock release is uncertain; no automatic retry.'):$result;
 }
 private function reserveLocked(string $shop,string $environment,int $productId,string $usageKey,string $workflow,string $model,?array $run):array|WP_Error {
  global $wpdb;$table=Tables::shop_ai_usage();$wpdb->last_error='';$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE idempotency_key=%s',$usageKey),ARRAY_A);
  if(!empty($wpdb->last_error))return $this->error('evidence_unavailable','Existing listing reservation could not be read.');
  if(is_array($old)){
   if((string)$old['shop_key']!==$shop||(string)$old['workflow']!==$workflow||(string)$old['stage']!=='listing_prepare'||(string)$old['model_key']!==$model||(int)$old['product_id']!==$productId||(string)$old['run_id']!==(string)($run['run_id']??'')||(float)$old['estimated_cost']!==0.0||(float)$old['actual_cost']!==0.0)return $this->error('replay_conflict','Existing listing key binds different approved product/bundle/run evidence.');
   return $old+['idempotent_replay'=>true,'quota_state'=>'RESERVED_LOCAL_QUANTITY','external_execution_performed'=>false];
  }
  $wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_policies().' WHERE shop_key=%s AND environment=%s AND state=%s',$shop,$environment,'ACTIVE'),ARRAY_A);
  if(!empty($wpdb->last_error))return $this->error('policy_unavailable','Listing quantity policy is unavailable.');
  if(!is_array($row))return $run!==null?$this->error('policy_required','Explicit planned listing run requires a reviewed shop/environment policy.'):['quota_state'=>'MANUAL_UNPLANNED','quantity_reserved'=>false,'external_execution_performed'=>false];
  $json=(string)($row['policy']??'');$policy=json_decode($json,true);
  if(!is_array($policy)||!is_string($row['policy_hash']??null)||!hash_equals((string)$row['policy_hash'],hash('sha256',$json))||($policy['shop_key']??'')!==$shop)return $this->error('policy_corrupt','Listing quota policy fingerprint or shop identity is invalid.');
  $stage=$policy['stages']['listing_prepare']??null;
  if(!is_array($stage))return $run!==null?$this->error('plan_required','Explicit listing run requires a reviewed listing_prepare quantity plan.'):['quota_state'=>'MANUAL_UNPLANNED','quantity_reserved'=>false,'external_execution_performed'=>false];
  $limit=$stage['limit']??null;if(!is_int($limit)||$limit<0||$limit>100000)return $this->error('limit_invalid','Listing preparation quantity limit is invalid.');
  $month=gmdate('Y-m-01 00:00:00');$wpdb->last_error='';$used=$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(quantity),0) FROM '.$table.' WHERE shop_key=%s AND stage=%s AND workflow=%s AND occurred_at>=%s',$shop,'listing_prepare',$workflow,$month));
  if(!empty($wpdb->last_error)||!is_numeric($used))return $this->error('quantity_unavailable','Listing preparation quantity evidence is unavailable.');
  if((int)$used>=$limit)return $this->error('limit_reached','Reviewed listing preparation quantity limit is reached.');
  $reservation=(new ShopAiGovernanceRepository())->recordUsage(['shop_key'=>$shop,'workflow'=>$workflow,'stage'=>'listing_prepare','model_key'=>$model,'product_id'=>$productId,'order_id'=>0,'quantity'=>1,'estimated_cost'=>0,'actual_cost'=>0,'currency'=>(string)($policy['currency']??'USD'),'run_context'=>$run],$usageKey);
  if(is_wp_error($reservation))return $reservation;
  if((string)($reservation['model_key']??'')!==$model)return $this->error('confirmation_uncertain','Listing reservation identity confirmation is uncertain.');
  return $reservation+['quota_state'=>'RESERVED_LOCAL_QUANTITY','external_execution_performed'=>false];
 }
 private function error(string $code,string $message):WP_Error {return new WP_Error('listing_preparation_quota_'.$code,$message,['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
