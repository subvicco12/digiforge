<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;
use WP_Error;

/** Consumes a short-lived production permit once. This class performs no provider/network action. */
final class ProductionExecutionPermitConsumer
{
 public function consume(array $permit,string $requestFingerprint,int $actorId,int $now):array|WP_Error{
  if(($permit['state']??'')!=='EXECUTION_AUTHORIZED'||($permit['executed']??null)!==false)
   return new WP_Error('production_permit_state_invalid','Unexecuted authorization evidence is required.',['status'=>409]);
  $auth=(array)($permit['authorization']??[]);$authHash=strtolower((string)($permit['authorization_hash']??''));
  $fingerprint=strtolower(trim($requestFingerprint));$bound=strtolower((string)($auth['request_fingerprint']??''));
  if(!preg_match('/^[a-f0-9]{64}$/',$authHash)||!preg_match('/^[a-f0-9]{64}$/',$fingerprint)||!hash_equals($bound,$fingerprint))
   return new WP_Error('production_permit_fingerprint_mismatch','Permit does not match the exact provider request fingerprint.',['status'=>409]);
  if($actorId<1)return new WP_Error('production_permit_actor_invalid','Explicit execution actor required.',['status'=>403]);
  if($now<(int)($auth['issued_at']??0)||$now>(int)($auth['expires_at']??0))
   return new WP_Error('production_permit_expired','Production permit is outside its authorization window.',['status'=>409]);
  $nonce=(string)($auth['nonce']??'');$consumed=ExecutionNonceLedger::consume($nonce,$authHash,$actorId);if(is_wp_error($consumed))return $consumed;
  $packageId=(int)($permit['package_id']??0);$packageHash=strtolower((string)($permit['package_hash']??''));if($packageId<1||!preg_match('/^[a-f0-9]{64}$/',$packageHash))return new WP_Error('production_permit_package_binding','Consumed permit requires exact package provenance.',['status'=>409]);
  global $wpdb;$binding=['package_id'=>$packageId,'package_hash'=>$packageHash,'authorization_hash'=>$authHash,'nonce_hash'=>hash('sha256',$nonce),'bound_by'=>$actorId,'bound_at'=>current_time('mysql',true)];
  if($wpdb->insert(Tables::pod_authorization_bindings(),$binding)===false){$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_bindings().' WHERE authorization_hash=%s LIMIT 1',$authHash),ARRAY_A);if(!is_array($existing)||!hash_equals((string)$existing['package_hash'],$packageHash)||(int)$existing['package_id']!==$packageId)return new WP_Error('production_permit_package_binding_conflict','Authorization provenance could not be persisted exactly.',['status'=>409]);}
  return ['state'=>'ADAPTER_CALL_PERMITTED','authorization_hash'=>$authHash,'evidence_hash'=>(string)($auth['evidence_hash']??''),'request_fingerprint'=>$fingerprint,'nonce_consumed'=>true,'consumed_by'=>$actorId,'consumed_at'=>$now,'external_execution_performed'=>false];
 }
}
