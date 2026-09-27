<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Read-only operator queue for ambiguous Printify outcomes. No retry or provider mutation. */
final class PrintifyUnknownOperatorReadModel
{
 public function summary():array{
  global $wpdb;
  $rows=$wpdb->get_results('SELECT authorization_hash,unknown_hash,request_fingerprint,recorded_at FROM '.Tables::pod_execution_unknowns().' ORDER BY id DESC',ARRAY_A);
  $items=[];$unresolved=0;$review=0;
  foreach((array)$rows as $row){
   $projection=(new PrintifyUnknownReconciliationReadModel())->project((string)$row['authorization_hash']);
   if(is_wp_error($projection)){$unresolved++;$items[]=['authorization_hash'=>(string)$row['authorization_hash'],'state'=>'RECONCILIATION_READ_ERROR','retry_permitted'=>false];continue;}
   $resolution=(string)($projection['resolution_state']??'PRINTIFY_RECONCILIATION_UNRESOLVED');
   $needs=$resolution==='PRINTIFY_RECONCILIATION_UNRESOLVED'||$resolution==='PRINTIFY_RECONCILIATION_UNKNOWN';if($needs)$unresolved++;else $review++;
   $items[]=['authorization_hash'=>(string)$row['authorization_hash'],'unknown_hash'=>(string)$row['unknown_hash'],'request_fingerprint'=>(string)$row['request_fingerprint'],'resolution_state'=>$resolution,'operator_action'=>$needs?'RECONCILE_BEFORE_ANY_RETRY':'REVIEW_RECONCILIATION_RESULT','retry_permitted'=>false,'reconciliation_required'=>$needs];
  }
  return ['unknown_outcomes'=>count((array)$rows),'unresolved_reconciliations'=>$unresolved,'resolved_review_required'=>$review,'retry_permitted'=>false,'external_execution_performed'=>false,'items'=>$items];
 }
}
