<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Persists immutable terminal execution failure evidence with exact replay semantics. Divergent authorization replay is always rejected. */
final class ExecutionFailureRepository
{
 /** @return array<string,mixed>|WP_Error */
 public static function save(array $record):array|WP_Error
 {
  if(($record['state']??'')!=='EXECUTION_FAILED_RECORDED'||!is_array($record['failure']??null))
   return new WP_Error('digiforge_failure_state','Valid execution failure record required.',['status'=>400]);
  $hash=(string)($record['failure_hash']??'');$r=$record['failure'];
  $auth=(string)($r['authorization_hash']??'');$nonce=(string)($r['authorization_nonce_hash']??'');
  if(!preg_match('/^[a-f0-9]{64}$/',$hash)||!preg_match('/^[a-f0-9]{64}$/',$auth)||!preg_match('/^[a-f0-9]{64}$/',$nonce))
   return new WP_Error('digiforge_failure_binding','Failure evidence binding is invalid.',['status'=>400]);
  if(($r['retry_permitted']??null)!==false||($r['nonce_consumed']??null)!==true)
   return new WP_Error('digiforge_failure_retry_state','Failure must remain non-retryable after nonce consumption.',['status'=>409]);
  global $wpdb;$table=Tables::pod_execution_failures();
  $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
  if(is_array($existing)){
   if(hash_equals((string)$existing['failure_hash'],$hash))return $existing;
   return new WP_Error('digiforge_failure_conflict','Authorization already has different terminal failure evidence.',['status'=>409]);
  }
  $row=['action'=>(string)$r['action'],'evidence_hash'=>(string)$r['evidence_hash'],'authorization_hash'=>$auth,'nonce_hash'=>$nonce,'executed_by'=>(int)$r['executed_by'],'recorded_at'=>gmdate('Y-m-d H:i:s',(int)$r['recorded_at']),'failure_hash'=>$hash,'created_at'=>current_time('mysql',true)];
  if($wpdb->insert($table,$row)===false){
   $winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
   if(is_array($winner)&&hash_equals((string)$winner['failure_hash'],$hash))return $winner;
   return new WP_Error('digiforge_failure_store','Execution failure could not be persisted.',['status'=>409]);
  }
  $row['id']=(int)$wpdb->insert_id;return $row;
 }
}
