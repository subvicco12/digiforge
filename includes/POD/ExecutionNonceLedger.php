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
  $existing=$wpdb->get_var($wpdb->prepare('SELECT authorization_hash FROM '.$table.' WHERE nonce_hash=%s LIMIT 1',$nonceHash));
  if(is_string($existing)&&$existing!=='')return new WP_Error('digiforge_execution_replay','Execution nonce has already been consumed.',['status'=>409]);
  $ok=$wpdb->insert($table,['nonce_hash'=>$nonceHash,'authorization_hash'=>$authorizationHash,'consumed_by'=>$consumedBy,'consumed_at'=>current_time('mysql',true)],['%s','%s','%d','%s']);
  if($ok===false){
   $winner=$wpdb->get_var($wpdb->prepare('SELECT authorization_hash FROM '.$table.' WHERE nonce_hash=%s LIMIT 1',$nonceHash));
   if(is_string($winner)&&$winner!=='')return new WP_Error('digiforge_execution_replay','Execution nonce was consumed concurrently.',['status'=>409]);
   return new WP_Error('digiforge_nonce_store','Execution nonce could not be consumed.',['status'=>500]);
  }
  return true;
 }
 public static function unused(string $nonce):bool
 {
  if(!preg_match('/^[A-Za-z0-9_-]{24,128}$/',$nonce))return false;
  global $wpdb;$table=Tables::pod_execution_nonces();
  return $wpdb->get_var($wpdb->prepare('SELECT nonce_hash FROM '.$table.' WHERE nonce_hash=%s LIMIT 1',hash('sha256',$nonce)))===null;
 }
}
