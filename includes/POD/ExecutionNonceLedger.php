<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Persistence boundary for one-time execution nonces. No provider action. */
final class ExecutionNonceLedger
{
 /** @return true|WP_Error */
 public static function consume(string $nonce,string $authorizationHash,int $consumedBy):true|WP_Error
 {
  if(!preg_match('/^[A-Za-z0-9_-]{24,128}$/',$nonce))return new WP_Error('digiforge_nonce_invalid','Valid execution nonce required.',['status'=>400]);
  if(!preg_match('/^[a-f0-9]{64}$/',$authorizationHash))return new WP_Error('digiforge_authorization_hash','Valid authorization hash required.',['status'=>400]);
  if($consumedBy<1)return new WP_Error('digiforge_nonce_actor','Valid execution actor required.',['status'=>403]);
  global $wpdb;$table=Tables::pod_execution_nonces();$nonceHash=hash('sha256',$nonce);
  $wpdb->flush();$existing=$wpdb->get_var($wpdb->prepare('SELECT authorization_hash FROM '.$table.' WHERE nonce_hash=%s LIMIT 1',$nonceHash));
  if(!empty($wpdb->last_error))return new WP_Error('digiforge_nonce_evidence_unavailable','Execution nonce evidence is unavailable; consumption is blocked.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);
  if(is_string($existing)&&$existing!=='')return new WP_Error('digiforge_execution_replay','Execution nonce has already been consumed.',['status'=>409]);
  $wpdb->last_error='';$ok=$wpdb->insert($table,['nonce_hash'=>$nonceHash,'authorization_hash'=>$authorizationHash,'consumed_by'=>$consumedBy,'consumed_at'=>current_time('mysql',true)],['%s','%s','%d','%s']);
  if($ok!==1){
   $wpdb->flush();$winner=$wpdb->get_var($wpdb->prepare('SELECT authorization_hash FROM '.$table.' WHERE nonce_hash=%s LIMIT 1',$nonceHash));
   if(!empty($wpdb->last_error))return new WP_Error('digiforge_nonce_confirmation_unavailable','Execution nonce may have been consumed but confirmation is unavailable; do not retry.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);
   if(is_string($winner)&&$winner!=='')return new WP_Error('digiforge_execution_replay','Execution nonce was consumed concurrently.',['status'=>409]);
   return new WP_Error('digiforge_nonce_store','Execution nonce consumption is unconfirmed; do not retry automatically.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);
  }
  return true;
 }
 public static function unused(string $nonce):bool|WP_Error
 {
  if(!preg_match('/^[A-Za-z0-9_-]{24,128}$/',$nonce))return false;
  global $wpdb;$table=Tables::pod_execution_nonces();
  $wpdb->flush();$found=$wpdb->get_var($wpdb->prepare('SELECT nonce_hash FROM '.$table.' WHERE nonce_hash=%s LIMIT 1',hash('sha256',$nonce)));if(!empty($wpdb->last_error))return new WP_Error('digiforge_nonce_evidence_unavailable','Execution nonce evidence is unavailable; unused state is not inferred.',['status'=>503,'retry_permitted'=>false,'external_execution_authorized'=>false]);return $found===null;
 }
}
