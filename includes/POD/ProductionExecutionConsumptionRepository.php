<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Atomic persistence boundary for one-time execution nonce + exact package provenance. No provider action. */
final class ProductionExecutionConsumptionRepository
{
 /** @return array|WP_Error */
 public static function consume(string $nonce,string $authorizationHash,int $packageId,string $packageHash,int $actorId):array|WP_Error
 {
  if(!preg_match('/^[A-Za-z0-9_-]{24,128}$/',$nonce))return new WP_Error('digiforge_nonce_invalid','Valid execution nonce required.',['status'=>400]);
  if(!preg_match('/^[a-f0-9]{64}$/',$authorizationHash))return new WP_Error('digiforge_authorization_hash','Valid authorization hash required.',['status'=>400]);
  if($packageId<1||!preg_match('/^[a-f0-9]{64}$/',$packageHash))return new WP_Error('production_permit_package_binding','Consumed permit requires exact package provenance.',['status'=>409]);
  if($actorId<1)return new WP_Error('digiforge_nonce_actor','Valid execution actor required.',['status'=>403]);
  global $wpdb;$nonceHash=hash('sha256',$nonce);$nonceTable=Tables::pod_execution_nonces();$bindingTable=Tables::pod_authorization_bindings();$packageTable=Tables::pod_authorization_packages();
  foreach([$nonceTable,$bindingTable,$packageTable] as $table){$wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(!empty($wpdb->last_error)||!is_string($engine)||strtoupper($engine)!=='INNODB')return new WP_Error('production_permit_transaction_engine','Atomic permit consumption requires InnoDB persistence; nothing was consumed.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
  $wpdb->last_error='';$existing=$wpdb->get_row($wpdb->prepare('SELECT authorization_hash FROM '.$nonceTable.' WHERE nonce_hash=%s LIMIT 1',$nonceHash),ARRAY_A);
  if(!empty($wpdb->last_error))return new WP_Error('production_permit_evidence_unavailable','Execution nonce evidence is unavailable; nothing was consumed.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);
  if(is_array($existing))return new WP_Error('digiforge_execution_replay','Execution nonce has already been consumed.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);
  if($wpdb->query('START TRANSACTION')===false)return new WP_Error('production_permit_transaction_unavailable','Transactional permit consumption is unavailable; nothing was consumed.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);
  try{
   $wpdb->last_error='';$package=$wpdb->get_row($wpdb->prepare('SELECT id,package_hash,state,external_execution_authorized,external_execution_performed FROM '.$packageTable.' WHERE id=%d FOR UPDATE',$packageId),ARRAY_A);
   if(!empty($wpdb->last_error)){$wpdb->query('ROLLBACK');return new WP_Error('production_permit_evidence_unavailable','Authorization package evidence is unavailable; nothing was consumed.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
   if(!is_array($package)||!hash_equals(strtolower((string)($package['package_hash']??'')),$packageHash)){$wpdb->query('ROLLBACK');return new WP_Error('production_permit_package_provenance_mismatch','Authorization package id/hash no longer matches durable package evidence.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
   $now=current_time('mysql',true);
   if($wpdb->insert($nonceTable,['nonce_hash'=>$nonceHash,'authorization_hash'=>$authorizationHash,'consumed_by'=>$actorId,'consumed_at'=>$now],['%s','%s','%d','%s'])===false){
    $wpdb->query('ROLLBACK');return self::nonceFailure($nonceHash);
   }
   $binding=['package_id'=>$packageId,'package_hash'=>$packageHash,'authorization_hash'=>$authorizationHash,'nonce_hash'=>$nonceHash,'bound_by'=>$actorId,'bound_at'=>$now];
   if($wpdb->insert($bindingTable,$binding,['%d','%s','%s','%s','%d','%s'])===false){
    $wpdb->query('ROLLBACK');
    $winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$bindingTable.' WHERE authorization_hash=%s OR nonce_hash=%s LIMIT 1',$authorizationHash,$nonceHash),ARRAY_A);
    if(is_array($winner))return new WP_Error('production_permit_package_binding_conflict','Authorization provenance conflicts with durable evidence.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);
    return new WP_Error('production_permit_package_binding_store','Authorization provenance could not be persisted; transaction was rolled back before any execution authority was granted.',['status'=>500,'retry_permitted'=>false,'external_execution_authorized'=>false]);
   }
   if($wpdb->query('COMMIT')===false)return self::commitUnknown($nonceHash,$authorizationHash,$packageId,$packageHash);
   return ['nonce_hash'=>$nonceHash,'authorization_hash'=>$authorizationHash,'package_id'=>$packageId,'package_hash'=>$packageHash,'bound_by'=>$actorId,'bound_at'=>$now,'retry_permitted'=>false,'external_execution_authorized'=>false];
  }catch(\Throwable $e){$wpdb->query('ROLLBACK');return new WP_Error('production_permit_consumption_store','Atomic permit consumption failed.',['status'=>500,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
 }
 private static function nonceFailure(string $nonceHash):WP_Error{
  global $wpdb;$winner=$wpdb->get_var($wpdb->prepare('SELECT authorization_hash FROM '.Tables::pod_execution_nonces().' WHERE nonce_hash=%s LIMIT 1',$nonceHash));
  if(is_string($winner)&&$winner!=='')return new WP_Error('digiforge_execution_replay','Execution nonce was consumed concurrently.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);
  return new WP_Error('digiforge_nonce_store','Execution nonce could not be consumed.',['status'=>500,'retry_permitted'=>false,'external_execution_authorized'=>false]);
 }
 private static function commitUnknown(string $nonceHash,string $authorizationHash,int $packageId,string $packageHash):WP_Error{
  $projection=(new ProductionPermitPersistenceReadModel())->project($nonceHash,$authorizationHash,$packageId,$packageHash);ProductionPermitPersistenceObservationRepository::observe($nonceHash,$authorizationHash,$packageId,$packageHash,$projection);
  return new WP_Error('production_permit_consumption_commit_unknown','COMMIT acknowledgement failed; persistence outcome requires reconciliation and must not be retried.',['status'=>503]+$projection);
 }
}
