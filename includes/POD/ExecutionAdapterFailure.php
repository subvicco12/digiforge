<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Converts a pre-send adapter WP_Error into minimal FAILED evidence. Post-send ambiguity must be returned explicitly as UNKNOWN by the adapter. */
final class ExecutionAdapterFailure
{
 /** @return array<string,mixed> */
 public static function fromError(array $permit,\WP_Error $error):array
 {
  $attempted=(bool)($error->get_error_data()['network_request_attempted']??false);
  return [
   'status'=>$attempted?'UNKNOWN':'FAILED',
   'action'=>(string)($permit['action']??''),
   'authorization_hash'=>(string)($permit['authorization_hash']??''),
   'evidence_hash'=>(string)($permit['evidence_hash']??''),
   'failure_category'=>$attempted?'AMBIGUOUS_TRANSPORT':'ADAPTER_ERROR',
   'failure_code'=>sanitize_key((string)$error->get_error_code()),
  ];
 }
}
