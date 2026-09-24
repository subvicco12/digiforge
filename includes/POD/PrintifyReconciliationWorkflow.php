<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
final class PrintifyReconciliationWorkflow {
 public function resolution(string $authorizationHash):array|WP_Error{
  $outcome=ExecutionOutcomeRepository::findByAuthorizationHash(strtolower(trim($authorizationHash)));if(is_wp_error($outcome))return $outcome;
  if(($outcome['state']??'')!=='EXECUTION_UNKNOWN')return new WP_Error('digiforge_printify_reconciliation_state','Persisted UNKNOWN execution required.',['status'=>409]);
  $u=is_array($outcome['unknown']??null)?$outcome['unknown']:[];return PrintifyReconciliationRepository::latest((string)($u['unknown_hash']??''));
 }
 public function reconcile(string $authorizationHash,int $integrationId): array|WP_Error {
  $outcome=ExecutionOutcomeRepository::findByAuthorizationHash(strtolower(trim($authorizationHash)));
  if(is_wp_error($outcome)) return $outcome;
  if(($outcome['state']??'')!=='EXECUTION_UNKNOWN') return new WP_Error('digiforge_printify_reconciliation_state','Persisted UNKNOWN execution required.',['status'=>409]);
  $row=is_array($outcome['unknown']??null)?$outcome['unknown']:[];$identity=json_decode((string)($row['reconciliation_identity']??''),true);
  $boundIntegration=absint(is_array($identity)?($identity['integration_id']??0):0);
  if($boundIntegration<1||$integrationId!==$boundIntegration)return new WP_Error('digiforge_printify_reconciliation_integration','Reconciliation integration must match persisted UNKNOWN evidence.',['status'=>409]);
  $result=(new PrintifyReconciliationLookup())->lookup($outcome,$boundIntegration);if(is_wp_error($result))return $result;
  return PrintifyReconciliationRepository::save($outcome,$result,$boundIntegration);
 }
}
