<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;
/** Persists immutable reconciliation-required UNKNOWN evidence. */
final class ExecutionUnknownRepository
{
 /** @return array<string,mixed>|WP_Error */
 public static function save(array $record):array|WP_Error
 {
  if(($record['state']??'')!=='EXECUTION_UNKNOWN_RECORDED'||!is_array($record['unknown']??null))return new WP_Error('digiforge_unknown_state','Valid UNKNOWN execution record required.',['status'=>400]);
  $r=$record['unknown'];$hash=(string)($record['unknown_hash']??'');$auth=(string)($r['authorization_hash']??'');$requestFingerprint=(string)($r['request_fingerprint']??'');$identity=is_array($r['reconciliation_identity']??null)?$r['reconciliation_identity']:[];
  if(!preg_match('/^[a-f0-9]{64}$/',$hash)||!preg_match('/^[a-f0-9]{64}$/',$auth)||($r['retry_permitted']??null)!==false||($r['reconciliation_required']??null)!==true||!preg_match('/^[a-f0-9]{64}$/',$requestFingerprint)||$identity===[])
   return new WP_Error('digiforge_unknown_binding','UNKNOWN evidence must remain reconciliation-required and non-retryable.',['status'=>409]);
  global $wpdb;$table=Tables::pod_execution_unknowns();
  foreach([Tables::pod_execution_receipts(),Tables::pod_execution_failures()] as $terminal){if(is_array($wpdb->get_row($wpdb->prepare('SELECT authorization_hash FROM '.$terminal.' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A)))return new WP_Error('digiforge_terminal_outcome_conflict','Authorization already has a conflicting terminal outcome.',['status'=>409]);}
  $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);
  if(is_array($existing))return hash_equals((string)$existing['unknown_hash'],$hash)?$existing:new WP_Error('digiforge_unknown_conflict','Authorization already has different UNKNOWN evidence.',['status'=>409]);
  $claim=ExecutionOutcomeClaimRepository::claim($auth,'UNKNOWN',$hash);if(is_wp_error($claim))return $claim;
  $row=['action'=>(string)$r['action'],'evidence_hash'=>(string)$r['evidence_hash'],'authorization_hash'=>$auth,'nonce_hash'=>(string)$r['authorization_nonce_hash'],'failure_category'=>sanitize_key((string)$r['failure_category']),'failure_code'=>sanitize_key((string)$r['failure_code']),'request_fingerprint'=>$requestFingerprint,'reconciliation_identity'=>wp_json_encode($identity),'executed_by'=>(int)$r['executed_by'],'recorded_at'=>gmdate('Y-m-d H:i:s',(int)$r['recorded_at']),'unknown_hash'=>$hash,'created_at'=>current_time('mysql',true)];
  if($wpdb->insert($table,$row)===false){$winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);if(is_array($winner)&&hash_equals((string)$winner['unknown_hash'],$hash))return $winner;return new WP_Error('digiforge_unknown_store','UNKNOWN execution evidence could not be persisted.',['status'=>409]);}
  $row['id']=(int)$wpdb->insert_id;return $row;
 }
}
