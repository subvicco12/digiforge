<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/**
 * Last in-process gate before any future mutating adapter.
 * It verifies authorization and atomically consumes the one-time nonce first.
 * This class does not call Etsy, Printify or any provider.
 */
final class ControlledExecutionGate
{
 /** @return array<string,mixed>|WP_Error */
 public static function authorize(array $authorization,string $action,string $evidenceHash,int $actor,int $now,string $requestFingerprint=''):array|WP_Error
 {
  if($actor<1)return new WP_Error('digiforge_execution_actor','Valid execution actor required.',['status'=>403]);
  $verified=ExecutionAuthorizationVerifier::verify(
   $authorization,$action,$evidenceHash,$now,
   static fn(string $nonce):bool=>ExecutionNonceLedger::unused($nonce),$requestFingerprint
  );
  if(is_wp_error($verified))return $verified;
  $payload=$authorization['authorization'];
  $consumed=ExecutionNonceLedger::consume((string)$payload['nonce'],(string)$authorization['authorization_hash'],$actor);
  if(is_wp_error($consumed))return $consumed;
  return [
   'state'=>'ADAPTER_CALL_PERMITTED',
   'action'=>strtoupper(trim($action)),
   'evidence_hash'=>strtolower(trim($evidenceHash)),
   'request_fingerprint'=>strtolower(trim($requestFingerprint)),
   'authorization_hash'=>(string)$authorization['authorization_hash'],
   'authorized_by'=>(int)$payload['authorized_by'],
   'executed_by'=>$actor,
   'nonce_consumed'=>true,
   'external_execution_performed'=>false,
  ];
 }
}
