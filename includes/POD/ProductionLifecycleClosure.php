<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Builds immutable lifecycle-closure evidence from a consumed authorization and one durable outcome. */
final class ProductionLifecycleClosure
{
 public function close(array $package,array $consumedOutcome,int $reviewerId):array|WP_Error{
  if($reviewerId<1)return new WP_Error('production_closure_reviewer_required','Authenticated human reviewer required.',['status'=>403]);
  if(($package['state']??'')!=='APPROVED_PACKAGE'||(int)($package['approved_by']??0)<1)return new WP_Error('production_closure_package_invalid','Human-approved production package required.',['status'=>409]);
  $state=(string)($consumedOutcome['state']??'');
  if(!in_array($state,['EXECUTION_SUCCEEDED','EXECUTION_FAILED','EXECUTION_UNKNOWN'],true)||($consumedOutcome['nonce_consumed']??null)!==true)
   return new WP_Error('production_closure_outcome_invalid','Consumed authorization with durable terminal outcome required.',['status'=>409]);
  $terminal=(array)($consumedOutcome['terminal_outcome']??[]);$auth=strtolower((string)($consumedOutcome['authorization_hash']??''));
  if(!preg_match('/^[a-f0-9]{64}$/',$auth))return new WP_Error('production_closure_authorization_invalid','Valid authorization binding required.',['status'=>409]);
  $payload=['package_id'=>(int)($package['id']??0),'package_hash'=>(string)($package['package_hash']??''),'authorization_hash'=>$auth,'outcome_state'=>$state,'outcome'=>$terminal,'closed_by'=>$reviewerId,'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>$state==='EXECUTION_SUCCEEDED'];
  $canonical=$payload;ksort($canonical);
  return ['state'=>'PRODUCTION_LIFECYCLE_CLOSED','closure'=>$payload,'closure_hash'=>hash('sha256',(string)wp_json_encode($canonical))];
 }
}
