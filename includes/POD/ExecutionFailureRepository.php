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
  $action=trim((string)($r['action']??''));$evidence=(string)($r['evidence_hash']??'');$actor=(int)($r['executed_by']??0);$recordedAt=(int)($r['recorded_at']??0);
  $category=sanitize_key((string)($r['failure_category']??''));$code=sanitize_key((string)($r['failure_code']??''));
  if($action===''||!preg_match('/^[a-f0-9]{64}$/',$evidence)||$actor<1||$recordedAt<1||$category===''||$code==='')
   return new WP_Error('digiforge_failure_payload','Failure payload is invalid.',['status'=>400]);
  global $wpdb;$table=Tables::pod_execution_failures();
  $unknown=$wpdb->get_row($wpdb->prepare('SELECT authorization_hash FROM '.Tables::pod_execution_unknowns().' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
  if(is_array($unknown))return new WP_Error('digiforge_terminal_outcome_conflict','Authorization requires reconciliation before terminal failure may be recorded.',['status'=>409]);
  $success=$wpdb->get_row($wpdb->prepare('SELECT authorization_hash FROM '.Tables::pod_execution_receipts().' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
  if(is_array($success))return new WP_Error('digiforge_terminal_outcome_conflict','Authorization already has terminal success evidence.',['status'=>409]);
  $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
  if(is_array($existing)){
   if(hash_equals((string)$existing['failure_hash'],$hash))return $existing;
   return new WP_Error('digiforge_failure_conflict','Authorization already has different terminal failure evidence.',['status'=>409]);
  }
  $row=['action'=>$action,'evidence_hash'=>$evidence,'authorization_hash'=>$auth,'nonce_hash'=>$nonce,'failure_category'=>$category,'failure_code'=>$code,'executed_by'=>$actor,'recorded_at'=>gmdate('Y-m-d H:i:s',$recordedAt),'failure_hash'=>$hash,'created_at'=>current_time('mysql',true)];
  if($wpdb->insert($table,$row)===false){
   $winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
   if(is_array($winner)&&hash_equals((string)$winner['failure_hash'],$hash))return $winner;
   return new WP_Error('digiforge_failure_store','Execution failure could not be persisted.',['status'=>409]);
  }
  $row['id']=(int)$wpdb->insert_id;return $row;
 }
}
