<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;use WP_Error;
/** Read-only lifecycle projection for a one-time production permit. */
final class ConsumedPermitOutcomeReadModel{
 public function project(string $authorizationHash):array|WP_Error{
  $authorizationHash=strtolower(trim($authorizationHash));if(!preg_match('/^[a-f0-9]{64}$/',$authorizationHash))return new WP_Error('consumed_permit_authorization_invalid','Valid authorization hash required.',['status'=>400]);
  global $wpdb;$nonce=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_execution_nonces().' WHERE authorization_hash=%s ORDER BY id DESC LIMIT 1',$authorizationHash),ARRAY_A);
  if(!is_array($nonce))return ['state'=>'PERMIT_NOT_CONSUMED','authorization_hash'=>$authorizationHash,'external_execution_state'=>'NOT_ATTEMPTED','external_execution_performed'=>false];
  $outcome=ExecutionOutcomeRepository::findByAuthorizationHash($authorizationHash);if(is_wp_error($outcome))return $outcome;$state=(string)($outcome['state']??'EXECUTION_OUTCOME_NOT_FOUND');
  $executionState=match($state){'EXECUTION_SUCCEEDED'=>'CONFIRMED_SUCCESS','EXECUTION_FAILED'=>'CONFIRMED_FAILURE','EXECUTION_UNKNOWN'=>'UNKNOWN',default=>'OUTCOME_PENDING'};
  return ['state'=>$state==='EXECUTION_OUTCOME_NOT_FOUND'?'CONSUMED_AWAITING_OUTCOME':$state,'authorization_hash'=>$authorizationHash,'nonce_consumed'=>true,'consumed_by'=>(int)$nonce['consumed_by'],'consumed_at'=>(string)$nonce['consumed_at'],'terminal_outcome'=>$outcome,'retry_permitted'=>$state==='EXECUTION_UNKNOWN'?false:null,'reconciliation_required'=>$state==='EXECUTION_UNKNOWN','external_execution_state'=>$executionState,'external_execution_performed'=>$state==='EXECUTION_SUCCEEDED'?true:null];
 }
}
