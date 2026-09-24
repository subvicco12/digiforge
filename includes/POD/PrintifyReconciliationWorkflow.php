<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
final class PrintifyReconciliationWorkflow {
 public function reconcile(string $authorizationHash,int $integrationId): array|WP_Error {
  $outcome=ExecutionOutcomeRepository::findByAuthorizationHash(strtolower(trim($authorizationHash)));
  if(is_wp_error($outcome)) return $outcome;
  if(($outcome['state']??'')!=='EXECUTION_UNKNOWN') return new WP_Error('digiforge_printify_reconciliation_state','Persisted UNKNOWN execution required.',['status'=>409]);
  return (new PrintifyReconciliationLookup())->lookup($outcome,$integrationId);
 }
}
