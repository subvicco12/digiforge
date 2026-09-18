<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/**
 * Builds non-retryable, hash-bound audit evidence for terminal adapter failure.
 * It records no raw authorization nonce and performs no external HTTP.
 */
final class ExecutionFailureRecord
{
 /** @return array<string,mixed>|WP_Error */
 public static function record(array $authorization,array $permit,array $adapterResult,int $executedBy,int $recordedAt):array|WP_Error
 {
  if(($permit['state']??'')!=='ADAPTER_CALL_PERMITTED'||($permit['nonce_consumed']??null)!==true)
   return new WP_Error('digiforge_failure_permit','Consumed execution permit required.',['status'=>403]);
  $normalized=ExecutionAdapterResult::normalize($permit,$adapterResult);
  if(is_wp_error($normalized))return $normalized;
  if(($normalized['status']??'')!=='FAILED')
   return new WP_Error('digiforge_failure_status','FAILED adapter result required.',['status'=>400]);
  $authHash=(string)($authorization['authorization_hash']??'');
  $nonce=(string)($authorization['authorization']['nonce']??'');
  if(!preg_match('/^[a-f0-9]{64}$/',$authHash)||$nonce==='')
   return new WP_Error('digiforge_failure_binding','Authorization binding is invalid.',['status'=>400]);
  if($executedBy<1)return new WP_Error('digiforge_failure_actor','Valid execution actor required.',['status'=>403]);
  $payload=[
   'action'=>(string)$normalized['action'],
   'evidence_hash'=>(string)$normalized['evidence_hash'],
   'authorization_hash'=>$authHash,
   'authorization_nonce_hash'=>hash('sha256',$nonce),
   'executed_by'=>$executedBy,
   'recorded_at'=>$recordedAt,
   'adapter_status'=>'FAILED',
   'failure_category'=>(string)($normalized['failure_category']??''),
   'failure_code'=>(string)($normalized['failure_code']??''),
   'nonce_consumed'=>true,
   'retry_permitted'=>false,
  ];
  $canonical=$payload;ksort($canonical);
  return ['state'=>'EXECUTION_FAILED_RECORDED','failure'=>$payload,'failure_hash'=>hash('sha256',(string)wp_json_encode($canonical))];
 }
}
