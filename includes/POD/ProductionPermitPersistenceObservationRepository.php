<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;

/** Durable, read-only audit trail for ambiguous permit persistence boundaries. */
final class ProductionPermitPersistenceObservationRepository{
 public static function observe(string $nonceHash,string $authorizationHash,int $packageId,string $packageHash,array $projection):void{
  if(!preg_match('/^[a-f0-9]{64}$/',$nonceHash)||!preg_match('/^[a-f0-9]{64}$/',$authorizationHash)||!preg_match('/^[a-f0-9]{64}$/',$packageHash)||$packageId<1)return;
  global $wpdb;$table=Tables::pod_permit_persistence_observations();$hash=hash('sha256',implode('|',[$nonceHash,$authorizationHash,(string)$packageId,$packageHash]));$now=current_time('mysql',true);$state=(string)($projection['persistence_state']??'UNKNOWN');if(!in_array($state,['UNKNOWN','PERSISTED_OBSERVED'],true))$state='UNKNOWN';
  $wpdb->last_error='';$existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$table.' WHERE observation_hash=%s LIMIT 1',$hash));
  if(!empty($wpdb->last_error))return;
  if($existing){$wpdb->update($table,['persistence_state'=>$state,'last_observed_at'=>$now],['id'=>(int)$existing],['%s','%s'],['%d']);return;}
  $wpdb->insert($table,['observation_hash'=>$hash,'nonce_hash'=>$nonceHash,'authorization_hash'=>$authorizationHash,'package_id'=>$packageId,'package_hash'=>$packageHash,'persistence_state'=>$state,'first_observed_at'=>$now,'last_observed_at'=>$now],['%s','%s','%s','%d','%s','%s','%s','%s']);
 }
 public static function recent(int $limit=50):array{
  $limit=max(1,min(200,$limit));global $wpdb;$raw=$wpdb->get_results($wpdb->prepare('SELECT observation_hash,nonce_hash,authorization_hash,package_id,package_hash,persistence_state,first_observed_at,last_observed_at FROM '.Tables::pod_permit_persistence_observations().' ORDER BY last_observed_at DESC,id DESC LIMIT %d',$limit),ARRAY_A);if(!is_array($raw)||!empty($wpdb->last_error))return ['query_state'=>'UNAVAILABLE','items'=>[],'count'=>null,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];$rows=$raw;foreach($rows as &$row){$row['read_only']=true;$row['retry_permitted']=false;$row['external_execution_authorized']=false;}unset($row);return ['query_state'=>'AVAILABLE','items'=>$rows,'count'=>count($rows),'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 public static function summary():array{
  global $wpdb;$table=Tables::pod_permit_persistence_observations();$wpdb->last_error='';$unknownRaw=$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE persistence_state='UNKNOWN'");if(!empty($wpdb->last_error)||!is_numeric($unknownRaw))return ['query_state'=>'UNAVAILABLE','unknown_count'=>null,'persisted_observed_count'=>null,'count'=>null,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];$wpdb->last_error='';$observedRaw=$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE persistence_state='PERSISTED_OBSERVED'");if(!empty($wpdb->last_error)||!is_numeric($observedRaw))return ['query_state'=>'UNAVAILABLE','unknown_count'=>null,'persisted_observed_count'=>null,'count'=>null,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];$unknown=(int)$unknownRaw;$observed=(int)$observedRaw;
  return ['query_state'=>'AVAILABLE','unknown_count'=>$unknown,'persisted_observed_count'=>$observed,'count'=>$unknown+$observed,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
}
