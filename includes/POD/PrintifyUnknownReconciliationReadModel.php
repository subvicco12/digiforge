<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Read-only UNKNOWN outcome projection. Never retries or performs provider execution. */
final class PrintifyUnknownReconciliationReadModel
{
 public function project(string $authorizationHash):array|WP_Error{
  $outcome=ExecutionOutcomeRepository::findByAuthorizationHash(strtolower(trim($authorizationHash)));if(is_wp_error($outcome))return $outcome;
  if(($outcome['state']??'')!=='EXECUTION_UNKNOWN')return ['state'=>(string)($outcome['state']??'EXECUTION_OUTCOME_NOT_FOUND'),'retry_permitted'=>false,'reconciliation_required'=>false];
  $unknown=(array)$outcome['unknown'];$unknownHash=(string)($unknown['unknown_hash']??'');if(!preg_match('/^[a-f0-9]{64}$/',$unknownHash))return new WP_Error('printify_unknown_evidence_invalid','UNKNOWN outcome is missing durable reconciliation identity.',['status'=>409]);
  $reconciliation=PrintifyReconciliationRepository::latest($unknownHash);if(is_wp_error($reconciliation))return $reconciliation;
  return ['state'=>'PRINTIFY_UNKNOWN_RECONCILIATION','authorization_hash'=>$authorizationHash,'unknown_hash'=>$unknownHash,'resolution_state'=>(string)($reconciliation['resolution_state']??'PRINTIFY_RECONCILIATION_UNRESOLVED'),'retry_permitted'=>false,'reconciliation_required'=>true,'reconciliation'=>$reconciliation];
 }
}
