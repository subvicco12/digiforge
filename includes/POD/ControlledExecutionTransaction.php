<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/**
 * Single provider-neutral transaction boundary for a controlled mutating adapter.
 * No provider implementation or external HTTP is introduced here. The nonce is consumed before adapter invocation and remains consumed on every terminal adapter outcome.
 */
final class ControlledExecutionTransaction
{
 /** @return array<string,mixed>|WP_Error */
 public static function execute(
  ExecutionAdapter $adapter,
  array $authorization,
  string $action,
  string $evidenceHash,
  int $actor,
  int $now,
  array $payload
 ):array|WP_Error {
  $prepared=ExecutionOrchestrator::prepare($authorization,$action,$evidenceHash,$actor,$now,$payload);
  if(is_wp_error($prepared))return $prepared;
  $permit=$prepared['permit']??null;
  if(!is_array($permit)||($permit['state']??'')!=='ADAPTER_CALL_PERMITTED'||($permit['nonce_consumed']??null)!==true)
   return new WP_Error('digiforge_transaction_permit','Consumed adapter permit required.',['status'=>403]);

  $raw=$adapter->execute($permit,$payload);
  if(is_wp_error($raw))return $raw;

  $normalized=ExecutionAdapterResult::normalize($permit,$raw);
  if(is_wp_error($normalized))return $normalized;
  if(($normalized['status']??'')!=='SUCCEEDED'){
   $failure=ExecutionFailureRecord::record($authorization,$permit,$normalized,$actor,$now);
   if(is_wp_error($failure))return $failure;
   $persistedFailure=ExecutionFailureRepository::save($failure);
   if(is_wp_error($persistedFailure))return $persistedFailure;
   return new WP_Error('digiforge_transaction_failed','Adapter execution did not succeed; nonce remains consumed.',['status'=>502,'failure_record'=>$failure,'persisted_failure'=>$persistedFailure]);
  }

  $receipt=ExecutionReceipt::record(
   $authorization,
   (string)$normalized['action'],
   (string)$normalized['external_reference'],
   $actor,
   $now
  );
  if(is_wp_error($receipt))return $receipt;

  $persisted=ExecutionReceiptRepository::save($receipt);
  if(is_wp_error($persisted))return $persisted;

  return [
   'state'=>'EXECUTION_PERSISTED',
   'adapter_result'=>$normalized,
   'execution_receipt'=>$receipt,
   'persisted_receipt'=>$persisted,
   'nonce_consumed'=>true,
   'external_execution_performed'=>true,
  ];
 }
}
