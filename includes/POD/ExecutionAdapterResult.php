<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Validates adapter output before immutable receipt creation. */
final class ExecutionAdapterResult
{
 /** @return array<string,mixed>|WP_Error */
 public static function normalize(array $permit,array $result):array|WP_Error
 {
  if(($permit['state']??'')!=='ADAPTER_CALL_PERMITTED'||($permit['nonce_consumed']??null)!==true)
   return new WP_Error('digiforge_adapter_permit','Consumed execution permit required.',['status'=>403]);
  if(($permit['external_execution_performed']??null)!==false)
   return new WP_Error('digiforge_adapter_permit_state','Permit must be pre-execution evidence.',['status'=>409]);
  $status=strtoupper(trim((string)($result['status']??'')));
  if(!in_array($status,['SUCCEEDED','FAILED','UNKNOWN'],true))
   return new WP_Error('digiforge_adapter_status','Adapter result must be SUCCEEDED, FAILED or UNKNOWN.',['status'=>400]);
  $ref=trim((string)($result['external_reference']??''));
  if($status==='SUCCEEDED'&&!preg_match('/^[A-Za-z0-9._:\/-]{3,160}$/',$ref))
   return new WP_Error('digiforge_adapter_reference','Successful execution requires provider reference.',['status'=>400]);
  $normalized=['status'=>$status,'external_reference'=>$ref,'action'=>(string)$permit['action'],'authorization_hash'=>(string)$permit['authorization_hash'],'evidence_hash'=>(string)$permit['evidence_hash']];
  if(in_array($status,['FAILED','UNKNOWN'],true)){
   $category=sanitize_key((string)($result['failure_category']??''));
   $code=sanitize_key((string)($result['failure_code']??''));
   if($category!=='')$normalized['failure_category']=$category;
   if($code!=='')$normalized['failure_code']=$code;
   if($status==='UNKNOWN'){
    $fingerprint=strtolower(trim((string)($result['request_fingerprint']??'')));$identity=is_array($result['reconciliation_identity']??null)?$result['reconciliation_identity']:[];
    if(!preg_match('/^[a-f0-9]{64}$/',$fingerprint)||$identity===[])return new WP_Error('digiforge_adapter_reconciliation_identity','UNKNOWN execution requires request fingerprint and reconciliation identity.',['status'=>400]);
    $normalized['request_fingerprint']=$fingerprint;$normalized['reconciliation_identity']=$identity;$normalized['reconciliation_required']=true;$normalized['retry_permitted']=false;
   }
  }
  return $normalized;
 }
}
