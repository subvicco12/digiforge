<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
/** Builds hash-bound non-retryable evidence for an ambiguous external mutation. */
final class ExecutionUnknownRecord
{
 /** @return array<string,mixed>|WP_Error */
 public static function record(array $authorization,array $permit,array $adapterResult,int $executedBy,int $recordedAt):array|WP_Error
 {
  $normalized=ExecutionAdapterResult::normalize($permit,$adapterResult);
  if(is_wp_error($normalized))return $normalized;
  if(($normalized['status']??'')!=='UNKNOWN'||($normalized['reconciliation_required']??null)!==true)
   return new WP_Error('digiforge_unknown_status','UNKNOWN reconciliation-required adapter result required.',['status'=>400]);
  $auth=(string)($authorization['authorization_hash']??'');$nonce=(string)($authorization['authorization']['nonce']??'');
  if(!preg_match('/^[a-f0-9]{64}$/',$auth)||$nonce===''||$executedBy<1||$recordedAt<1)
   return new WP_Error('digiforge_unknown_binding','Unknown execution binding is invalid.',['status'=>400]);
  $payload=['action'=>(string)$normalized['action'],'evidence_hash'=>(string)$normalized['evidence_hash'],'authorization_hash'=>$auth,
   'authorization_nonce_hash'=>hash('sha256',$nonce),'executed_by'=>$executedBy,'recorded_at'=>$recordedAt,
   'adapter_status'=>'UNKNOWN','failure_category'=>(string)($normalized['failure_category']??'AMBIGUOUS_TRANSPORT'),
   'failure_code'=>(string)($normalized['failure_code']??'unknown'),'request_fingerprint'=>(string)$normalized['request_fingerprint'],'reconciliation_identity'=>$normalized['reconciliation_identity'],'nonce_consumed'=>true,
   'retry_permitted'=>false,'reconciliation_required'=>true];
  $canonical=$payload;ksort($canonical);
  return ['state'=>'EXECUTION_UNKNOWN_RECORDED','unknown'=>$payload,'unknown_hash'=>hash('sha256',(string)wp_json_encode($canonical))];
 }
}
