<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Read-only operator queue for ambiguous Printify outcomes. No retry or provider mutation. */
final class PrintifyUnknownOperatorReadModel
{
 public function summary():array{
  global $wpdb;
  $rows=$wpdb->get_results('SELECT id,authorization_hash,unknown_hash,request_fingerprint,recorded_at FROM '.Tables::pod_execution_unknowns().' ORDER BY id DESC',ARRAY_A);
  if(!is_array($rows)||!empty($wpdb->last_error))return ['query_state'=>'UNAVAILABLE','unknown_outcomes'=>null,'unresolved_reconciliations'=>null,'resolved_review_required'=>null,'retry_permitted'=>false,'external_execution_state'=>'UNKNOWN','external_execution_performed'=>null,'items'=>[]];
  $items=[];$unresolved=0;$review=0;
  foreach((array)$rows as $row){
   $projection=(new PrintifyUnknownReconciliationReadModel())->project((string)$row['authorization_hash']);
   if(is_wp_error($projection)){$unresolved++;$items[]=['id'=>(int)$row['id'],'authorization_hash'=>(string)$row['authorization_hash'],'state'=>'RECONCILIATION_READ_ERROR','retry_permitted'=>false,'external_execution_authorized'=>false,'read_only'=>true];continue;}
   $resolution=(string)($projection['resolution_state']??'PRINTIFY_RECONCILIATION_UNRESOLVED');
   $needs=$resolution==='PRINTIFY_RECONCILIATION_UNRESOLVED'||$resolution==='PRINTIFY_RECONCILIATION_UNKNOWN';$acknowledged=false;if(!$needs&&isset($projection['reconciliation']['reconciliation_hash'])){$acknowledged=(bool)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::pod_reconciliation_acknowledgements().' WHERE reconciliation_hash=%s',(string)$projection['reconciliation']['reconciliation_hash']));}if($needs)$unresolved++;elseif(!$acknowledged)$review++;
   $items[]=['id'=>(int)$row['id'],'authorization_hash'=>(string)$row['authorization_hash'],'unknown_hash'=>(string)$row['unknown_hash'],'request_fingerprint'=>(string)$row['request_fingerprint'],'resolution_state'=>$resolution,'lifecycle_state'=>$needs?'EXECUTION_UNKNOWN_RECONCILIATION_REQUIRED':($acknowledged?'EXECUTION_UNKNOWN_ACKNOWLEDGED':'EXECUTION_UNKNOWN_REVIEW_REQUIRED'),'operator_action'=>$needs?'RECONCILE_BEFORE_ANY_RETRY':($acknowledged?'RECONCILIATION_ACKNOWLEDGED':'REVIEW_RECONCILIATION_RESULT'),'retry_permitted'=>false,'external_execution_authorized'=>false,'read_only'=>true,'reconciliation_required'=>$needs];
  }
  return ['query_state'=>'AVAILABLE','unknown_outcomes'=>count($rows),'unresolved_reconciliations'=>$unresolved,'resolved_review_required'=>$review,'retry_permitted'=>false,'external_execution_state'=>'UNKNOWN','external_execution_performed'=>null,'items'=>$items];
 }
}
