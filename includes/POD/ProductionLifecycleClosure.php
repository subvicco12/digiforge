<?php
declare(strict_types=1);
namespace DigiForge\POD;use WP_Error;
/** Builds immutable closure evidence only for unambiguous terminal outcomes. */
final class ProductionLifecycleClosure{
 public function close(array $package,array $consumedOutcome,int $reviewerId):array|WP_Error{
  if($reviewerId<1)return new WP_Error('production_closure_reviewer_required','Authenticated human reviewer required.',['status'=>403]);
  if(($package['state']??'')!=='APPROVED_PACKAGE'||(int)($package['approved_by']??0)<1)return new WP_Error('production_closure_package_invalid','Human-approved production package required.',['status'=>409]);
  $state=(string)($consumedOutcome['state']??'');if(!in_array($state,['EXECUTION_SUCCEEDED','EXECUTION_FAILED'],true)||($consumedOutcome['nonce_consumed']??null)!==true)return new WP_Error('production_closure_outcome_invalid','Consumed authorization with unambiguous durable terminal outcome required.',['status'=>409]);
  $terminal=(array)($consumedOutcome['terminal_outcome']??[]);$auth=strtolower((string)($consumedOutcome['authorization_hash']??''));$packageHash=strtolower((string)($package['package_hash']??''));
  if((int)($package['id']??0)<1||!preg_match('/^[a-f0-9]{64}$/',$packageHash)||!preg_match('/^[a-f0-9]{64}$/',$auth))return new WP_Error('production_closure_authorization_invalid','Valid package and authorization binding required.',['status'=>409]);
  $executionState=$state==='EXECUTION_SUCCEEDED'?'CONFIRMED_SUCCESS':'CONFIRMED_FAILURE';
  $payload=['package_id'=>(int)$package['id'],'package_hash'=>$packageHash,'authorization_hash'=>$auth,'outcome_state'=>$state,'outcome'=>$terminal,'closed_by'=>$reviewerId,'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_state'=>$executionState];
  $canonical=$payload;ksort($canonical);return ['state'=>'PRODUCTION_LIFECYCLE_CLOSED','closure'=>$payload,'closure_hash'=>hash('sha256',(string)wp_json_encode($canonical))];
 }
}
