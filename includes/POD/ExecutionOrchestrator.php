<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/**
 * Provider-neutral orchestration boundary. It is intentionally adapter-agnostic
 * and cannot bypass ControlledExecutionGate.
 */
final class ExecutionOrchestrator
{
 /** @return array<string,mixed>|WP_Error */
 public static function prepare(array $authorization,string $action,string $evidenceHash,int $actor,int $now,array $payload):array|WP_Error
 {
  $permit=ControlledExecutionGate::authorize($authorization,$action,$evidenceHash,$actor,$now);
  if(is_wp_error($permit))return $permit;
  if(($permit['state']??'')!=='ADAPTER_CALL_PERMITTED'||($permit['nonce_consumed']??null)!==true)
   return new WP_Error('digiforge_orchestrator_permit','Consumed adapter permit required.',['status'=>403]);
  return [
   'state'=>'ADAPTER_INVOCATION_PREPARED',
   'permit'=>$permit,
   'payload'=>$payload,
   'adapter_invoked'=>false,
   'external_execution_performed'=>false,
  ];
 }
}
