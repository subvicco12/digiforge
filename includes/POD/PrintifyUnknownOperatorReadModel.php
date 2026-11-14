<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Read-only operator queue for ambiguous Printify outcomes. No retry or provider mutation. */
final class PrintifyUnknownOperatorReadModel
{
 public function summary(?string $shop=null):array{
  global $wpdb;
  $unknown=Tables::pod_execution_unknowns();$scope=$shop===null?'':' WHERE EXISTS (SELECT 1 FROM '.Tables::pod_authorization_bindings().' scope_binding INNER JOIN '.Tables::pod_authorization_packages().' scope_package ON scope_package.id=scope_binding.package_id AND scope_package.package_hash=scope_binding.package_hash INNER JOIN '.Tables::orders().' scope_order ON scope_order.id=scope_package.order_id WHERE scope_binding.authorization_hash='.$unknown.'.authorization_hash AND '.\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,'scope_order').')';
  $wpdb->last_error='';$rows=$wpdb->get_results('SELECT id,authorization_hash,unknown_hash,request_fingerprint,recorded_at FROM '.$unknown.$scope.' ORDER BY id DESC',ARRAY_A);
  if(!is_array($rows)||!empty($wpdb->last_error))return ['query_state'=>'UNAVAILABLE','unknown_outcomes'=>null,'unresolved_reconciliations'=>null,'resolved_review_required'=>null,'retry_permitted'=>false,'external_execution_state'=>'UNKNOWN','external_execution_performed'=>null,'items'=>[]];
  $items=[];$unresolved=0;$review=0;
  foreach((array)$rows as $row){
   $projection=(new PrintifyUnknownReconciliationReadModel())->project((string)$row['authorization_hash']);
   if(is_wp_error($projection)){$unresolved++;$items[]=['id'=>(int)$row['id'],'authorization_hash'=>(string)$row['authorization_hash'],'state'=>'RECONCILIATION_READ_ERROR','retry_permitted'=>false,'external_execution_authorized'=>false,'read_only'=>true];continue;}
   $resolution=(string)($projection['resolution_state']??'PRINTIFY_RECONCILIATION_UNRESOLVED');
   $needs=$resolution==='PRINTIFY_RECONCILIATION_UNRESOLVED'||$resolution==='PRINTIFY_RECONCILIATION_UNKNOWN';$acknowledged=false;if(!$needs&&isset($projection['reconciliation']['reconciliation_hash'])){$ackRaw=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::pod_reconciliation_acknowledgements().' WHERE reconciliation_hash=%s',(string)$projection['reconciliation']['reconciliation_hash']));if(!empty($wpdb->last_error)||!is_numeric($ackRaw)){$unresolved++;$items[]=['id'=>(int)$row['id'],'authorization_hash'=>(string)$row['authorization_hash'],'state'=>'ACKNOWLEDGEMENT_EVIDENCE_UNAVAILABLE','retry_permitted'=>false,'external_execution_authorized'=>false,'read_only'=>true];continue;}$acknowledged=((int)$ackRaw)>0;}if($needs)$unresolved++;elseif(!$acknowledged)$review++;
   $items[]=['id'=>(int)$row['id'],'authorization_hash'=>(string)$row['authorization_hash'],'unknown_hash'=>(string)$row['unknown_hash'],'request_fingerprint'=>(string)$row['request_fingerprint'],'resolution_state'=>$resolution,'lifecycle_state'=>$needs?'EXECUTION_UNKNOWN_RECONCILIATION_REQUIRED':($acknowledged?'EXECUTION_UNKNOWN_ACKNOWLEDGED':'EXECUTION_UNKNOWN_REVIEW_REQUIRED'),'operator_action'=>$needs?'RECONCILE_BEFORE_ANY_RETRY':($acknowledged?'RECONCILIATION_ACKNOWLEDGED':'REVIEW_RECONCILIATION_RESULT'),'retry_permitted'=>false,'external_execution_authorized'=>false,'read_only'=>true,'reconciliation_required'=>$needs];
  }
  return ['query_state'=>'AVAILABLE','unknown_outcomes'=>count($rows),'unresolved_reconciliations'=>$unresolved,'resolved_review_required'=>$review,'retry_permitted'=>false,'external_execution_state'=>'UNKNOWN','external_execution_performed'=>null,'items'=>$items];
 }
}
