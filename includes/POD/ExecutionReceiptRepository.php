<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Persists immutable adapter execution evidence with exact replay semantics. */
final class ExecutionReceiptRepository
{
 /** @return array<string,mixed>|WP_Error */
 public static function save(array $receipt):array|WP_Error
 {
  if(($receipt['state']??'')!=='EXECUTION_RECORDED'||!is_array($receipt['receipt']??null))
   return new WP_Error('digiforge_receipt_state','Valid execution receipt required.',['status'=>400]);
  $hash=(string)($receipt['receipt_hash']??'');
  if(!preg_match('/^[a-f0-9]{64}$/',$hash))
   return new WP_Error('digiforge_receipt_hash','Valid receipt hash required.',['status'=>400]);
  $r=$receipt['receipt'];$auth=(string)($r['authorization_hash']??'');$nonce=(string)($r['authorization_nonce_hash']??'');
  if(!preg_match('/^[a-f0-9]{64}$/',$auth)||!preg_match('/^[a-f0-9]{64}$/',$nonce))
   return new WP_Error('digiforge_receipt_binding','Receipt authorization binding is invalid.',['status'=>400]);
  $action=trim((string)($r['action']??''));$evidence=(string)($r['evidence_hash']??'');$external=trim((string)($r['external_reference']??''));$actor=(int)($r['executed_by']??0);$executedAt=(int)($r['executed_at']??0);
  if($action===''||!preg_match('/^[a-f0-9]{64}$/',$evidence)||$external===''||$actor<1||$executedAt<1)
   return new WP_Error('digiforge_receipt_payload','Receipt payload is invalid.',['status'=>400]);
  global $wpdb;$table=Tables::pod_execution_receipts();
  $unknown=$wpdb->get_row($wpdb->prepare('SELECT authorization_hash FROM '.Tables::pod_execution_unknowns().' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
  if(is_array($unknown))return new WP_Error('digiforge_terminal_outcome_conflict','Authorization requires reconciliation before terminal success may be recorded.',['status'=>409]);
  $failure=$wpdb->get_row($wpdb->prepare('SELECT authorization_hash FROM '.Tables::pod_execution_failures().' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
  if(is_array($failure))return new WP_Error('digiforge_terminal_outcome_conflict','Authorization already has terminal failure evidence.',['status'=>409]);
  $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
  if(is_array($existing)){
   if(hash_equals((string)$existing['receipt_hash'],$hash))return $existing;
   return new WP_Error('digiforge_receipt_conflict','Authorization already has different execution evidence.',['status'=>409]);
  }
  $row=['action'=>$action,'evidence_hash'=>$evidence,'authorization_hash'=>$auth,'nonce_hash'=>$nonce,'external_reference'=>$external,'executed_by'=>$actor,'executed_at'=>gmdate('Y-m-d H:i:s',$executedAt),'receipt_hash'=>$hash,'created_at'=>current_time('mysql',true)];
  if($wpdb->insert($table,$row)===false){
   $winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
   if(is_array($winner)&&hash_equals((string)$winner['receipt_hash'],$hash))return $winner;
   return new WP_Error('digiforge_receipt_store','Execution receipt could not be persisted.',['status'=>409]);
  }
  $row['id']=(int)$wpdb->insert_id;return $row;
 }
}
