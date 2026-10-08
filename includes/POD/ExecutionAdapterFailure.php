<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Converts a provably pre-send adapter error to FAILED; absent attempt evidence remains UNKNOWN. */
final class ExecutionAdapterFailure
{
 /** @return array<string,mixed> */
 public static function fromError(array $permit,\WP_Error $error):array
 {
  $data=$error->get_error_data();$data=is_array($data)?$data:[];$attempted=($data['network_request_attempted']??null)!==false;
  return [
   'status'=>$attempted?'UNKNOWN':'FAILED',
   'action'=>(string)($permit['action']??''),
   'authorization_hash'=>(string)($permit['authorization_hash']??''),
   'evidence_hash'=>(string)($permit['evidence_hash']??''),
   'failure_category'=>$attempted?'AMBIGUOUS_TRANSPORT':'ADAPTER_ERROR',
   'failure_code'=>sanitize_key((string)$error->get_error_code()),
   'request_fingerprint'=>$attempted?(string)($data['request_fingerprint']??''):'',
   'reconciliation_identity'=>$attempted&&is_array($data['reconciliation_identity']??null)?$data['reconciliation_identity']:[],
  ];
 }
}
