<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;use WP_Error;
final class PrintifyReconciliationRepository{
 public static function latest(string $unknownHash):array|WP_Error{
  $unknownHash=strtolower(trim($unknownHash));if(!preg_match('/^[a-f0-9]{64}$/',$unknownHash))return new WP_Error('digiforge_printify_reconciliation_unknown','Valid UNKNOWN hash required.',['status'=>400]);
  global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_printify_reconciliations().' WHERE unknown_hash=%s ORDER BY id DESC LIMIT 1',$unknownHash),ARRAY_A);
  if($row===null&&trim((string)$wpdb->last_error)!=='')return new WP_Error('digiforge_printify_reconciliation_read','Reconciliation evidence could not be read.',['status'=>503]);
  return is_array($row)?$row:['resolution_state'=>'PRINTIFY_RECONCILIATION_UNRESOLVED','unknown_hash'=>$unknownHash,'retry_permitted'=>false,'reconciliation_required'=>true];
 }
 public static function save(array $outcome,array $result,int $integrationId):array|WP_Error{
  $u=is_array($outcome['unknown']??null)?$outcome['unknown']:[];$auth=strtolower((string)($u['authorization_hash']??''));$unknown=strtolower((string)($u['unknown_hash']??''));$fp=strtolower((string)($u['request_fingerprint']??''));
  $state=(string)($result['state']??'');if(!preg_match('/^[a-f0-9]{64}$/',$auth)||!preg_match('/^[a-f0-9]{64}$/',$unknown)||!preg_match('/^[a-f0-9]{64}$/',$fp)||$integrationId<1||!in_array($state,['PRINTIFY_RECONCILIATION_CONFIRMED','PRINTIFY_RECONCILIATION_NOT_CONFIRMED','PRINTIFY_RECONCILIATION_UNKNOWN'],true))return new WP_Error('digiforge_printify_reconciliation_evidence','Bound reconciliation evidence required.',['status'=>409]);
  if(!hash_equals($fp,strtolower((string)($result['request_fingerprint']??'')))||($result['retry_permitted']??null)!==false)return new WP_Error('digiforge_printify_reconciliation_evidence','Reconciliation result must remain fingerprint-bound and non-retryable.',['status'=>409]);
  $evidence=['authorization_hash'=>$auth,'unknown_hash'=>$unknown,'request_fingerprint'=>$fp,'integration_id'=>$integrationId,'resolution_state'=>$state,'provider_order_id'=>sanitize_text_field((string)($result['provider_order_id']??'')),'provider_status'=>sanitize_key((string)($result['provider_status']??'')),'http_status'=>(int)($result['http_status']??0),'pagination_required'=>(bool)($result['pagination_required']??false),'pages_checked'=>(int)($result['pages_checked']??0),'retry_permitted'=>false,'reconciliation_required'=>(bool)($result['reconciliation_required']??true)];
  $canonical=$evidence;ksort($canonical);$hash=hash('sha256',(string)wp_json_encode($canonical));global $wpdb;$table=Tables::pod_printify_reconciliations();
  $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE unknown_hash=%s AND reconciliation_hash=%s LIMIT 1',$unknown,$hash),ARRAY_A);if(is_array($existing))return $existing;
  $row=['authorization_hash'=>$auth,'unknown_hash'=>$unknown,'request_fingerprint'=>$fp,'integration_id'=>$integrationId,'resolution_state'=>$state,'evidence'=>wp_json_encode($evidence),'reconciliation_hash'=>$hash,'created_at'=>current_time('mysql',true)];
  if($wpdb->insert($table,$row)===false)return new WP_Error('digiforge_printify_reconciliation_store','Reconciliation evidence could not be persisted.',['status'=>409]);$row['id']=(int)$wpdb->insert_id;return $row;
 }
}
