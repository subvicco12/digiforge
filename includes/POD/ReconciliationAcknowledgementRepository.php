<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;use WP_Error;
final class ReconciliationAcknowledgementRepository{
 public static function save(array $record):array|WP_Error{
  if(($record['state']??'')!=='RECONCILIATION_ACKNOWLEDGED'||!is_array($record['acknowledgement']??null))return new WP_Error('reconciliation_ack_state','Valid acknowledgement evidence required.',['status'=>400]);
  $a=$record['acknowledgement'];$hash=strtolower((string)($record['acknowledgement_hash']??''));$rh=strtolower((string)($a['reconciliation_hash']??''));$auth=strtolower((string)($a['authorization_hash']??''));
  if(!preg_match('/^[a-f0-9]{64}$/',$hash)||!preg_match('/^[a-f0-9]{64}$/',$rh)||!preg_match('/^[a-f0-9]{64}$/',$auth)||($a['retry_permitted']??null)!==false)return new WP_Error('reconciliation_ack_binding','Acknowledgement binding is invalid.',['status'=>409]);
  global $wpdb;$table=Tables::pod_reconciliation_acknowledgements();$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE reconciliation_hash=%s LIMIT 1',$rh),ARRAY_A);
  if(is_array($existing))return hash_equals((string)$existing['acknowledgement_hash'],$hash)?$existing:new WP_Error('reconciliation_ack_conflict','Reconciliation already has different acknowledgement evidence.',['status'=>409]);
  $row=['authorization_hash'=>$auth,'unknown_hash'=>(string)$a['unknown_hash'],'reconciliation_hash'=>$rh,'resolution_state'=>(string)$a['resolution_state'],'decision'=>(string)$a['decision'],'reviewed_by'=>(int)$a['reviewed_by'],'acknowledgement_hash'=>$hash,'created_at'=>current_time('mysql',true)];
  if($wpdb->insert($table,$row)===false)return new WP_Error('reconciliation_ack_store','Acknowledgement could not be persisted.',['status'=>409]);$row['id']=(int)$wpdb->insert_id;return $row;
 }
}
